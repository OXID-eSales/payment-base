<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Refund;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Refund\ProviderChargebackLedger;
use OxidEsales\PaymentBase\Repository\DoctrineContractRepository;
use OxidEsales\PaymentBase\Repository\DoctrineTransactionRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Psr\Log\NullLogger;

/**
 * VR-21 F3 (S-M6) — a provider reports the chargeback total of a payment, not each chargeback: one audit
 * row per change of that total, the delta; a reversal is a negative row; a replay changes nothing.
 */
#[Group('integration')]
final class ProviderChargebackLedgerTest extends TestCase
{
    private Connection $connection;

    private ProviderChargebackLedger $ledger;

    private string $contractId;

    protected function setUp(): void
    {
        $this->connection = ContainerFactory::getInstance()->getContainer()
            ->get(ConnectionProviderInterface::class)->get();
        $this->ledger = new ProviderChargebackLedger(
            $this->connection,
            new DoctrineContractRepository($this->connection, new NullLogger()),
            new DoctrineTransactionRepository($this->connection),
            new NullLogger(),
        );

        $this->contractId = 'it_vr21cb_' . bin2hex(random_bytes(8));
        (new DoctrineContractRepository($this->connection, new NullLogger()))->save(new PaymentContract(1, 'it_vr21_user', BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [],
            'totalGross' => 100.0, 'totalNet' => 84.03, 'totalVat' => 15.97, 'currency' => 'EUR',
        ]), $this->contractId));
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM oe_payments_transaction WHERE OXCONTRACTID = :id',
            ['id' => $this->contractId]
        );
        $this->connection->executeStatement('DELETE FROM oe_payments_contract WHERE OXID = :id', ['id' => $this->contractId]);
    }

    public function testAGrowingTotalBooksTheDeltaEachTime(): void
    {
        $first = $this->ledger->book($this->contractId, null, 30.0, 'EUR', 'webhook');
        $second = $this->ledger->book($this->contractId, null, 50.0, 'EUR', 'webhook');

        self::assertTrue($first->booked);
        self::assertSame(30.0, $first->delta);
        self::assertSame(20.0, $second->delta);
        self::assertSame(50.0, $second->chargedBackTotal);
        self::assertSame([30.0, 20.0], $this->auditAmounts());
    }

    public function testAReversalBooksTheNegativeDelta(): void
    {
        $this->ledger->book($this->contractId, null, 50.0, 'EUR', 'webhook');

        $reversal = $this->ledger->book($this->contractId, null, 0.0, 'EUR', 'webhook');

        self::assertTrue($reversal->booked);
        self::assertSame(-50.0, $reversal->delta);
        self::assertSame(0.0, $reversal->chargedBackTotal);
        self::assertSame([50.0, -50.0], $this->auditAmounts());
    }

    public function testAReplayOfTheSameTotalBooksNothing(): void
    {
        $this->ledger->book($this->contractId, 'chb_1', 30.0, 'EUR', 'webhook');

        $replay = $this->ledger->book($this->contractId, 'chb_1', 30.0, 'EUR', 'webhook');

        self::assertFalse($replay->booked);
        self::assertSame(0.0, $replay->delta);
        self::assertSame(30.0, $replay->chargedBackTotal);
        self::assertSame([30.0], $this->auditAmounts());
    }

    public function testNothingChargedBackAndNothingBookedBooksNothing(): void
    {
        $booking = $this->ledger->book($this->contractId, null, 0.0, 'EUR', 'webhook');

        self::assertFalse($booking->booked);
        self::assertSame([], $this->auditAmounts());
    }

    public function testTheProviderChargebackIdIsKeptOnTheAuditRowAndItsReversal(): void
    {
        $this->ledger->book($this->contractId, 'chb_1', 30.0, 'EUR', 'webhook');
        $this->ledger->book($this->contractId, 'chb_1', 0.0, 'EUR', 'webhook');

        $ids = $this->connection->fetchFirstColumn(
            "SELECT OXTRANSACTIONID FROM oe_payments_transaction
             WHERE OXCONTRACTID = :c AND OXTYPE = 'chargeback' ORDER BY OXAMOUNT DESC",
            ['c' => $this->contractId]
        );
        self::assertSame(['chb_1', 'chb_1:reversal'], $ids);
    }

    public function testAChargebackInAnotherCurrencyIsRefusedAndBooksNothing(): void
    {
        try {
            $this->ledger->book($this->contractId, null, 30.0, 'CHF', 'webhook');
            self::fail('a chargeback in another currency must not be booked');
        } catch (InvalidArgumentException) {
        }

        self::assertSame([], $this->auditAmounts());
    }

    public function testAnUnknownContractIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no contract');

        $this->ledger->book('it_vr21cb_missing', null, 30.0, 'EUR', 'webhook');
    }

    /**
     * @return list<float>
     */
    private function auditAmounts(): array
    {
        return array_map(
            'floatval',
            $this->connection->fetchFirstColumn(
                "SELECT OXAMOUNT FROM oe_payments_transaction
                 WHERE OXCONTRACTID = :c AND OXTYPE = 'chargeback' ORDER BY OXAMOUNT DESC",
                ['c' => $this->contractId]
            )
        );
    }
}
