<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Reconciliation;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Exception;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Reconciliation\OxpaidReconciliationService;
use OxidEsales\PaymentBase\Reconciliation\ProviderPaymentStatus;
use OxidEsales\PaymentBase\Reconciliation\ProviderPaymentStatusInterface;
use OxidEsales\PaymentBase\Reconciliation\ReconciliationResult;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractFulfillmentServiceInterface;
use OxidEsales\PaymentBase\Service\OrderPaymentStateServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Union of the Stripe and Mollie reconciliation service tests, against the shared service.
 */
#[CoversClass(OxpaidReconciliationService::class)]
#[CoversClass(ReconciliationResult::class)]
#[CoversClass(ProviderPaymentStatus::class)]
final class OxpaidReconciliationServiceTest extends TestCase
{
    private Connection&MockObject $connection;
    private ContractRepositoryInterface&MockObject $contracts;
    private ContractFulfillmentServiceInterface&MockObject $fulfillment;
    private OrderPaymentStateServiceInterface&MockObject $orderState;
    private ProviderPaymentStatusInterface&MockObject $port;
    private LoggerInterface&MockObject $logger;
    private OxpaidReconciliationService $service;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->contracts = $this->createMock(ContractRepositoryInterface::class);
        $this->fulfillment = $this->createMock(ContractFulfillmentServiceInterface::class);
        $this->orderState = $this->createMock(OrderPaymentStateServiceInterface::class);
        $this->port = $this->createMock(ProviderPaymentStatusInterface::class);
        $this->port->method('provider')->willReturn('acme');
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new OxpaidReconciliationService(
            $this->connection,
            $this->contracts,
            $this->fulfillment,
            $this->orderState,
            [$this->port],
            $this->logger,
        );
    }

    public function testProvidersListsTheRegisteredPorts(): void
    {
        self::assertSame(['acme'], $this->service->providers());
    }

    public function testFindUnpaidOrdersReturnsEmptyArrayWhenNoneExist(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([]);

        self::assertSame([], $this->service->findUnpaidOrders('acme', 1));
    }

    public function testFindUnpaidOrdersQueriesUnpaidOrdersOfTheProviderWithinTheAge(): void
    {
        $this->connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::callback(static fn (string $sql): bool => str_contains($sql, "OXPAID = '0000-00-00 00:00:00'")
                    && str_contains($sql, 'oe_payments_contract')
                    && str_contains($sql, 'c.OXPROVIDER = ?')
                    && str_contains($sql, 'DATE_SUB(NOW(), INTERVAL ? DAY)')),
                ['acme', 14]
            )
            ->willReturn([['OXID' => 'o1']]);

        self::assertSame([['OXID' => 'o1']], $this->service->findUnpaidOrders('acme', 14));
    }

    public function testReconcileOrderFailsWithoutContract(): void
    {
        $this->contracts->method('findByOrderId')->with('o1')->willReturn(null);
        $this->port->expects(self::never())->method('statusOf');
        $this->orderState->expects(self::never())->method('updatePaidTimestamp');

        $result = $this->service->reconcileOrder('o1');

        self::assertFalse($result->success);
        self::assertSame(ReconciliationResult::ACTION_NO_CONTRACT, $result->action);
        self::assertStringContainsString('No contract found', $result->reason);
    }

    public function testReconcileOrderFailsWhenTheContractHasNoProviderOrderId(): void
    {
        $this->contracts->method('findByOrderId')->willReturn($this->contract(providerOrderId: null));
        $this->port->expects(self::never())->method('statusOf');

        $result = $this->service->reconcileOrder('o1');

        self::assertSame(ReconciliationResult::ACTION_NO_CONTRACT, $result->action);
    }

    public function testReconcileOrderSkipsWhenThePaymentIsNotPaid(): void
    {
        $this->contracts->method('findByOrderId')->willReturn($this->contract());
        $this->port->method('statusOf')->with('pay_1')->willReturn(ProviderPaymentStatus::notPaid('open'));
        $this->orderState->expects(self::never())->method('updatePaidTimestamp');
        $this->fulfillment->expects(self::never())->method('fulfill');

        $result = $this->service->reconcileOrder('o1');

        self::assertFalse($result->success);
        self::assertSame(ReconciliationResult::ACTION_SKIPPED, $result->action);
        self::assertStringContainsString('open', $result->reason);
    }

    public function testReconcileOrderDoesNotWriteOxpaidWhenTheProviderReportsNoTimestamp(): void
    {
        $this->contracts->method('findByOrderId')->willReturn($this->contract());
        $this->port->method('statusOf')->willReturn(ProviderPaymentStatus::paidWithoutTimestamp('paid'));
        $this->orderState->expects(self::never())->method('updatePaidTimestamp');
        $this->fulfillment->expects(self::never())->method('fulfill');

        $result = $this->service->reconcileOrder('o1');

        self::assertSame(ReconciliationResult::ACTION_SKIPPED, $result->action);
        self::assertStringContainsString('manual review', $result->reason);
    }

    public function testReconcileOrderStampsTheProviderTimeAndFulfilsTheContract(): void
    {
        $contract = $this->contract();
        $paidAt = new DateTimeImmutable('2026-10-01 12:30:00');
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->port->method('statusOf')->willReturn(ProviderPaymentStatus::paidAt('paid', $paidAt));
        $this->orderState->expects(self::once())->method('updatePaidTimestamp')->with('o1', $paidAt)->willReturn(true);
        $this->fulfillment->expects(self::once())->method('fulfill')->with($contract)->willReturn(true);

        $result = $this->service->reconcileOrder('o1');

        self::assertTrue($result->success);
        self::assertSame(ReconciliationResult::ACTION_UPDATED, $result->action);
        self::assertTrue($result->contractUpdated);
        self::assertSame('pay_1', $result->providerOrderId);
    }

    public function testReconcileOrderReportsAnUnchangedContractAsUpdatedOrder(): void
    {
        $this->contracts->method('findByOrderId')->willReturn($this->contract());
        $this->port->method('statusOf')->willReturn(ProviderPaymentStatus::paidAt('paid', new DateTimeImmutable()));
        $this->orderState->method('updatePaidTimestamp')->willReturn(true);
        $this->fulfillment->method('fulfill')->willReturn(false);
        $this->logger->expects(self::once())->method('info')->with(
            self::logicalAnd(self::stringContains('CONTRACT_UNCHANGED'), self::logicalNot(self::stringContains('NO_CONTRACT')))
        );

        $result = $this->service->reconcileOrder('o1');

        self::assertTrue($result->success);
        self::assertFalse($result->contractUpdated);
    }

    public function testReconcileOrderIsANoOpWhenTheOrderIsAlreadyPaid(): void
    {
        $this->contracts->method('findByOrderId')->willReturn($this->contract());
        $this->port->method('statusOf')->willReturn(ProviderPaymentStatus::paidAt('paid', new DateTimeImmutable()));
        $this->orderState->method('updatePaidTimestamp')->willReturn(false);
        $this->fulfillment->expects(self::never())->method('fulfill');

        $result = $this->service->reconcileOrder('o1');

        self::assertFalse($result->success);
        self::assertSame(ReconciliationResult::ACTION_SKIPPED, $result->action);
        self::assertStringContainsString('already consistent', $result->reason);
    }

    public function testReconcileOrderTurnsAProviderApiErrorIntoAnErrorResult(): void
    {
        $this->contracts->method('findByOrderId')->willReturn($this->contract());
        $this->port->method('statusOf')->willThrowException(new Exception('API down'));
        $this->logger->expects(self::once())->method('error')->with(self::stringContains('API down'));

        $result = $this->service->reconcileOrder('o1');

        self::assertFalse($result->success);
        self::assertSame(ReconciliationResult::ACTION_ERROR, $result->action);
        self::assertSame('API down', $result->reason);
    }

    public function testReconcileOrderReportsAnErrorWhenTheProviderHasNoPort(): void
    {
        $this->contracts->method('findByOrderId')->willReturn($this->contract(provider: 'unknown'));

        $result = $this->service->reconcileOrder('o1');

        self::assertSame(ReconciliationResult::ACTION_ERROR, $result->action);
        self::assertStringContainsString('unknown', $result->reason);
    }

    public function testReconcileAllProcessesEveryUnpaidOrder(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([['OXID' => 'o1'], ['OXID' => 'o2']]);
        $this->contracts->method('findByOrderId')->willReturn($this->contract());
        $this->port->method('statusOf')->willReturn(ProviderPaymentStatus::notPaid('open'));

        $results = $this->service->reconcileAll('acme', 7, false);

        self::assertCount(2, $results);
        self::assertSame(['o1', 'o2'], array_map(static fn (ReconciliationResult $r): string => $r->orderId, $results));
    }

    public function testReconcileAllReportsOrdersWithoutAContractAsFailures(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([['OXID' => 'o1']]);
        $this->contracts->method('findByOrderId')->willReturn(null);

        $results = $this->service->reconcileAll('acme', 7, false);

        self::assertFalse($results[0]->success);
        self::assertSame(ReconciliationResult::ACTION_NO_CONTRACT, $results[0]->action);
    }

    public function testReconcileAllDryRunMakesNoChangesAndNeverReportsSuccess(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([
            ['OXID' => 'o1', 'OXORDERNR' => 5, 'OXORDERDATE' => '2026-10-01', 'OXPROVIDERORDERID' => 'pay_1'],
        ]);
        $this->port->expects(self::never())->method('statusOf');
        $this->orderState->expects(self::never())->method('updatePaidTimestamp');
        $this->fulfillment->expects(self::never())->method('fulfill');

        $results = $this->service->reconcileAll('acme', 7, true);

        self::assertCount(1, $results);
        self::assertSame(ReconciliationResult::ACTION_DRY_RUN, $results[0]->action);
        self::assertFalse($results[0]->success);
        self::assertSame('pay_1', $results[0]->providerOrderId);
        self::assertStringContainsString('#5', $results[0]->reason);
    }

    public function testResultAsArray(): void
    {
        $result = new ReconciliationResult('o1', 'pay_1', true, 'updated', 'ok', true);

        self::assertSame(
            [
                'order_id' => 'o1', 'provider_order_id' => 'pay_1', 'success' => true,
                'action' => 'updated', 'reason' => 'ok', 'contract_updated' => true,
            ],
            $result->toArray()
        );
        self::assertFalse((new ReconciliationResult('o', 'p', false, 'skipped', 'r'))->contractUpdated);
    }

    private function contract(?string $providerOrderId = 'pay_1', string $provider = 'acme'): PaymentContractInterface
    {
        $contract = $this->createStub(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn($providerOrderId);
        $contract->method('getProvider')->willReturn($provider);

        return $contract;
    }
}
