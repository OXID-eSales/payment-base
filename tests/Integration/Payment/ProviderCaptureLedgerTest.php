<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Payment;

use Doctrine\DBAL\Connection;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\MoneyInvariantViolation;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Payment\ProviderCaptureLedger;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\DoctrineContractRepository;
use OxidEsales\PaymentBase\Repository\DoctrineTransactionRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * VR-22 A3 — the admin action and the provider's webhook both report one capture; the contract counts it once.
 * Against the real contract and transaction tables.
 */
#[Group('integration')]
final class ProviderCaptureLedgerTest extends TestCase
{
    private Connection $connection;

    private ContractRepositoryInterface $contracts;

    private ProviderCaptureLedger $ledger;

    private string $contractId;

    protected function setUp(): void
    {
        $this->connection = ContainerFactory::getInstance()->getContainer()->get(ConnectionProviderInterface::class)->get();
        $this->contracts = new DoctrineContractRepository($this->connection);
        $this->ledger = new ProviderCaptureLedger($this->connection, $this->contracts, new DoctrineTransactionRepository($this->connection), new NullLogger());

        $this->contractId = 'it_vr22_' . bin2hex(random_bytes(8));
        $contract = new PaymentContract(1, 'it_vr22_user', BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [],
            'totalGross' => 100.0, 'totalNet' => 84.03, 'totalVat' => 15.97, 'currency' => 'EUR',
        ]), $this->contractId);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_' . $this->contractId);
        $contract->transitionToPending();
        $contract->setProvider('paypal', 'PAYPAL-ORDER-1');
        $contract->authorize();
        $this->contracts->save($contract);
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement('DELETE FROM oe_payments_transaction WHERE OXCONTRACTID = :id', ['id' => $this->contractId]);
        $this->connection->executeStatement('DELETE FROM oe_payments_contract WHERE OXID = :id', ['id' => $this->contractId]);
    }

    public function testTheAdminActionAndTheWebhookReportingOneCaptureBookItOnce(): void
    {
        $first = $this->ledger->book($this->contractId, 'CAP-1', 40.0, 'EUR', 'admin');
        $second = $this->ledger->book($this->contractId, 'CAP-1', 40.0, 'EUR', 'webhook');

        self::assertTrue($first->booked);
        self::assertFalse($second->booked);
        self::assertSame(40.0, $second->capturedTotal);
        self::assertSame(40.0, $this->contracts->findById($this->contractId)?->getCapturedAmount());
        self::assertSame(1, $this->captureRows('CAP-1'));
    }

    public function testTwoDifferentCapturesAddUp(): void
    {
        $this->ledger->book($this->contractId, 'CAP-1', 40.0, 'EUR', 'admin');
        $booking = $this->ledger->book($this->contractId, 'CAP-2', 25.0, 'EUR', 'admin');

        self::assertSame(65.0, $booking->capturedTotal);
    }

    public function testACaptureAboveTheAuthorisationIsRefusedAndBooksNothing(): void
    {
        $this->ledger->book($this->contractId, 'CAP-1', 80.0, 'EUR', 'admin');

        try {
            $this->ledger->book($this->contractId, 'CAP-2', 30.0, 'EUR', 'admin');
            self::fail('over-capture must not be booked');
        } catch (MoneyInvariantViolation) {
        }

        self::assertSame(0, $this->captureRows('CAP-2'));
        self::assertSame(80.0, $this->contracts->findById($this->contractId)?->getCapturedAmount());
    }

    private function captureRows(string $providerCaptureId): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM oe_payments_transaction WHERE OXCONTRACTID = :c AND OXTYPE = 'capture' AND OXTRANSACTIONID = :r",
            ['c' => $this->contractId, 'r' => $providerCaptureId]
        );
    }
}
