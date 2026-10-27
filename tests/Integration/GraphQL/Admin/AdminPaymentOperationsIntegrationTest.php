<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\GraphQL\Admin;

use Doctrine\DBAL\Connection;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\GraphQL\Admin\Audit\Service\AuditRecorder;
use OxidEsales\PaymentBase\Admin\PaymentPanelRegistry;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Contract\Transaction;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminPaymentOperations;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\DbalRequestShopOrders;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\OperationIdempotency;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\OxidAdminAudit;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PanelProviderActionRunner;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentContractDetailsReader;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\DoctrineContractRepository;
use OxidEsales\PaymentBase\Repository\DoctrineIdempotencyRepository;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use OxidEsales\PaymentBase\Tests\Integration\Support\InMemoryAuditChain;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\AdapterEffects;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\CountingStepUp;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\FakePanelProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Capture, refund and void against the real database (contracts, transactions, the idempotency table, the order
 * rows, the admin API's audit chain), with ONLY the provider adapter faked at the panel-provider boundary. A
 * provider sandbox is not involved: the money movement itself belongs to each provider module's own suites.
 */
#[Group('integration')]
final class AdminPaymentOperationsIntegrationTest extends IntegrationTestCase
{
    private const ORDER_ID = 'a10-op-order';
    private const OTHER_SHOP_ORDER_ID = 'a10-op-order-shop2';

    private ?ContractRepositoryInterface $contractRepository = null;
    private InMemoryAuditChain $auditChain;
    private FakePanelProvider $adapter;
    private CountingStepUp $stepUp;

    public function setUp(): void
    {
        parent::setUp();
        if (!class_exists(AuditRecorder::class)) {
            self::markTestSkipped('oxid-admin-graphql is not installed');
        }
        $this->adapter = new FakePanelProvider('paypal', AdapterEffects::successful($this->contracts()));
        $this->stepUp = new CountingStepUp();
        $this->auditChain = new InMemoryAuditChain();
        $this->connection()->executeStatement('INSERT INTO oxorder (OXID, OXSHOPID) VALUES (?, 1), (?, 2)', [self::ORDER_ID, self::OTHER_SHOP_ORDER_ID]);
    }

    public function testRefund_RealDatabase_RefundsOnceAndAuditsTheCall(): void
    {
        $this->saveFulfilledContract(self::ORDER_ID, 100.0);

        $first = $this->operations()->refund(self::ORDER_ID, 30.0, 'Damaged in transit', 'a10-refund-key-1');
        $retry = $this->operations()->refund(self::ORDER_ID, 30.0, 'Damaged in transit', 'a10-refund-key-1');

        self::assertNull($first->refusal());
        self::assertNull($retry->refusal());
        self::assertCount(1, $this->adapter->calls, 'the retry with the same key does not reach the provider again');
        self::assertSame(30.0, $this->contracts()->findByOrderId(self::ORDER_ID)?->getRefundedAmount());
        self::assertSame(2, $this->stepUp->required, 'both calls demanded the second factor');

        self::assertCount(2, $this->auditChain->rows, 'one audit row per call, the retry included');
        self::assertSame(
            [
                'operation' => 'adminPaymentRefund',
                'entityType' => 'order',
                'entityId' => self::ORDER_ID,
                'fields' => ['amount', 'reason'],
                'values' => ['amount' => 30],
                'result' => 'success',
                'errorCode' => null,
            ],
            $this->auditChain->row(0),
            'the amount is the only value; the reason text is not recorded',
        );
        self::assertStringNotContainsString('Damaged', $this->auditChain->rows[0]->record->changedFieldsJson);
    }

    public function testRefund_ExceedingTheRefundable_IsRefusedAndAuditedAsAFailure(): void
    {
        $this->saveFulfilledContract(self::ORDER_ID, 100.0);
        $refusal = $this->operations()->refund(self::ORDER_ID, 500.0, null, 'a10-refund-key-2')->refusal();

        self::assertSame('exceeds_refundable', $refusal?->code);
        self::assertSame([], $this->adapter->calls);
        self::assertCount(1, $this->auditChain->rows);
        self::assertSame(['failure', 'validation_failed'], [$this->auditChain->row(0)['result'], $this->auditChain->row(0)['errorCode']]);
    }

    public function testCapture_OfAnAuthorisedContract_AndTheTransactionsAreReadBack(): void
    {
        $contract = $this->saveAuthorizedContract(self::ORDER_ID, 80.0);
        $this->transactions()->save(new Transaction('a10-tx-auth', 1, self::ORDER_ID, $contract->getId(), 'paypal', 'authorization', 'completed', 80.0, 'EUR'));

        $result = $this->operations()->capture(self::ORDER_ID, null, 'a10-capture-key-1');

        self::assertNull($result->refusal());
        self::assertSame('fulfilled', $result->payment()->state);
        self::assertSame(80.0, $result->payment()->amountCaptured);
        self::assertSame('authorization', $result->payment()->transactions[0]->type);
    }

    public function testVoid_OfAnAuthorisedContract(): void
    {
        $this->saveAuthorizedContract(self::ORDER_ID, 80.0);

        $result = $this->operations()->void(self::ORDER_ID, 'a10-void-key-1');

        self::assertNull($result->refusal());
        self::assertSame('cancelled', $result->payment()->state);
    }

    public function testAnOrderOfAnotherShop_IsNotFound(): void
    {
        $this->saveFulfilledContract(self::OTHER_SHOP_ORDER_ID, 100.0);

        $refusal = $this->operations()->refund(self::OTHER_SHOP_ORDER_ID, 10.0, null, 'a10-refund-key-3')->refusal();

        self::assertSame('not_found', $refusal?->kind);
        self::assertSame([], $this->adapter->calls);
    }

    public function testTheIdempotencyRecordLivesInTheModulesTableAndSurvivesTheCall(): void
    {
        $this->saveFulfilledContract(self::ORDER_ID, 100.0);

        $this->operations()->refund(self::ORDER_ID, 10.0, null, 'a10-refund-key-4');

        $row = $this->connection()->fetchAssociative(
            'SELECT OXOPERATION, OXSTATUS, OXORDERID FROM oe_payments_idempotency WHERE OXORDERID = ?',
            [self::ORDER_ID],
        );
        self::assertSame(['admin_payment_refund', 'completed', self::ORDER_ID], array_values((array) $row));
    }

    private function operations(): AdminPaymentOperations
    {
        $connection = $this->connection();

        return new AdminPaymentOperations(
            new PaymentContractDetailsReader($this->contracts(), $this->transactions()),
            new PanelProviderActionRunner(new PaymentPanelRegistry([$this->adapter]), $this->contracts(), new NullLogger()),
            new OperationIdempotency(new DoctrineIdempotencyRepository($connection)),
            $this->stepUp,
            new OxidAdminAudit(InMemoryAuditChain::recorder($this->auditChain)),
            new DbalRequestShopOrders($connection),
            new NullLogger(),
        );
    }

    private function saveFulfilledContract(string $orderId, float $captured): PaymentContract
    {
        $contract = $this->saveAuthorizedContract($orderId, $captured);
        $contract->captureAuthorization();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder($orderId);
        $contract->setCapturedAmount($captured);
        $contract->fulfill();
        $this->contracts()->save($contract);

        return $contract;
    }

    private function saveAuthorizedContract(string $orderId, float $gross): PaymentContract
    {
        $contract = new PaymentContract(1, 'a10-user', BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => $gross,
            'totalNet' => round($gross / 1.19, 2),
            'totalVat' => round($gross - $gross / 1.19, 2),
            'currency' => 'EUR',
        ]));
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished($orderId);
        $contract->transitionToPending();
        $contract->setProvider('paypal', 'a10-prov-' . $orderId);
        $contract->authorize();
        $this->contracts()->save($contract);

        return $contract;
    }

    /**
     * Built on the connection the test transaction covers: the container's `doctrine.dbal.connection` is another
     * connection, and what it writes is committed and survives the test.
     */
    private function contracts(): ContractRepositoryInterface
    {
        return $this->contractRepository ??= new DoctrineContractRepository($this->connection());
    }

    private function transactions(): TransactionRepositoryInterface
    {
        return new \OxidEsales\PaymentBase\Repository\DoctrineTransactionRepository($this->connection());
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = ContainerFactory::getInstance()->getContainer()->get(ConnectionProviderInterface::class)->get();

        return $connection;
    }
}
