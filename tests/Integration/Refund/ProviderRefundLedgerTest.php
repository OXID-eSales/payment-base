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
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Logging\ThrowableSummary;
use OxidEsales\PaymentBase\Refund\ProviderRefundLedger;
use OxidEsales\PaymentBase\Refund\RefundSettledPolicyInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\DoctrineContractRepository;
use OxidEsales\PaymentBase\Repository\DoctrineTransactionRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Psr\Log\NullLogger;

/**
 * VR-21 F3 — the admin action and the provider's webhook both report one refund;
 * the contract counts it once. Against the real contract and transaction tables.
 */
#[Group('integration')]
final class ProviderRefundLedgerTest extends TestCase
{
    private Connection $connection;

    private ContractRepositoryInterface $contracts;

    private ProviderRefundLedger $ledger;

    private string $contractId;

    /** @var list<array{0: string, 1: bool, 2: bool}> contract id, refund succeeded, contract fully refunded */
    private array $settled = [];

    protected function setUp(): void
    {
        $container = ContainerFactory::getInstance()->getContainer();
        $this->connection = $container->get(ConnectionProviderInterface::class)->get();
        // The ledger locks the contract row on its connection; the repositories must write on the same one,
        // as services.yaml wires all three (doctrine.dbal.connection).
        $this->contracts = new DoctrineContractRepository($this->connection, new NullLogger(), new ThrowableSummary([]));
        $this->ledger = new ProviderRefundLedger(
            $this->connection,
            $this->contracts,
            new DoctrineTransactionRepository($this->connection),
            $this->recordingPolicy(),
            new NullLogger(),
        );

        $this->contractId = 'it_vr21_' . bin2hex(random_bytes(8));
        $contract = new PaymentContract(1, 'it_vr21_user', BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [],
            'totalGross' => 100.0, 'totalNet' => 84.03, 'totalVat' => 15.97, 'currency' => 'EUR',
        ]), $this->contractId);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_' . $this->contractId);
        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder('order_' . $this->contractId);
        $contract->fulfill();
        $contract->setProvider('paypal', 'PAYPAL-ORDER-1');
        $contract->setCapturedAmount(100.0);
        $this->contracts->save($contract);
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM oe_payments_transaction WHERE OXCONTRACTID = :id',
            ['id' => $this->contractId]
        );
        $this->connection->executeStatement('DELETE FROM oe_payments_contract WHERE OXID = :id', ['id' => $this->contractId]);
    }

    public function testTheAdminActionAndTheWebhookReportingOneRefundBookItOnce(): void
    {
        $first = $this->ledger->book($this->contractId, 'REFUND-1', 30.0, 'EUR', 'admin', true);
        $second = $this->ledger->book($this->contractId, 'REFUND-1', 30.0, 'EUR', 'webhook', true);

        self::assertTrue($first->booked);
        self::assertFalse($second->booked);
        self::assertSame(30.0, $second->refundedTotal);
        self::assertSame(30.0, $this->contracts->findById($this->contractId)?->getRefundedAmount());
        self::assertSame(1, $this->refundRows('REFUND-1'));
    }

    public function testTwoDifferentRefundsAreBothBooked(): void
    {
        $this->ledger->book($this->contractId, 'REFUND-1', 30.0, 'EUR', 'admin', true);
        $booking = $this->ledger->book($this->contractId, 'REFUND-2', 20.0, 'eur', 'webhook', true);

        self::assertTrue($booking->booked);
        self::assertSame(50.0, $booking->refundedTotal);
        self::assertSame(50.0, $this->contracts->findById($this->contractId)?->getRemainingRefundableAmount());
    }

    public function testARefundInAnotherCurrencyIsRefusedAndBooksNothing(): void
    {
        try {
            $this->ledger->book($this->contractId, 'REFUND-1', 30.0, 'CHF', 'webhook', true);
            self::fail('a refund in another currency must not be booked');
        } catch (InvalidArgumentException) {
        }

        self::assertSame(0, $this->refundRows('REFUND-1'));
        self::assertNull($this->contracts->findById($this->contractId)?->getRefundedAmount());
    }

    public function testAnUnknownContractIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no contract');

        $this->ledger->book('it_vr21_missing', 'REFUND-1', 30.0, 'EUR', 'webhook', true);
    }

    public function testEveryReportIsSettledWithWhetherTheRefundSucceeded_ThePolicyDecidesWhatThatMeans(): void
    {
        $this->ledger->book($this->contractId, 'REFUND-1', 40.0, 'EUR', 'admin', false);
        $this->ledger->book($this->contractId, 'REFUND-2', 60.0, 'EUR', 'webhook', true);

        self::assertSame([
            [$this->contractId, false, false],
            [$this->contractId, true, true],
        ], $this->settled);
    }

    public function testAPendingRefundReportedAgainAsSucceededIsSettledThen_NotBookedTwice(): void
    {
        $this->ledger->book($this->contractId, 'REFUND-1', 100.0, 'EUR', 'admin', false);
        $again = $this->ledger->book($this->contractId, 'REFUND-1', 100.0, 'EUR', 'webhook', true);

        self::assertFalse($again->booked);
        self::assertSame(100.0, $this->contracts->findById($this->contractId)?->getRefundedAmount());
        self::assertSame([
            [$this->contractId, false, true],
            [$this->contractId, true, true],
        ], $this->settled);
    }

    public function testARefusedRefundIsNotSettled(): void
    {
        try {
            $this->ledger->book($this->contractId, 'REFUND-1', 30.0, 'CHF', 'webhook', true);
        } catch (InvalidArgumentException) {
        }

        self::assertSame([], $this->settled);
    }

    private function recordingPolicy(): RefundSettledPolicyInterface
    {
        $policy = $this->createMock(RefundSettledPolicyInterface::class);
        $policy->method('settle')->willReturnCallback(
            function (PaymentContractInterface $contract, bool $succeeded): bool {
                $this->settled[] = [(string) $contract->getId(), $succeeded, $contract->isFullyRefunded()];

                return false;
            }
        );

        return $policy;
    }

    private function refundRows(string $providerRefundId): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM oe_payments_transaction
             WHERE OXCONTRACTID = :c AND OXTYPE = 'refund' AND OXTRANSACTIONID = :r",
            ['c' => $this->contractId, 'r' => $providerRefundId]
        );
    }
}
