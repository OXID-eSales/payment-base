<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Contract;

use OxidEsales\PaymentBase\Adapter\ContractLinkedOrderUpdaterInterface;
use OxidEsales\PaymentBase\Contract\ContractState;
use OxidEsales\PaymentBase\Contract\ContractTerminationService;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Contract\TerminationOutcome;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Union of the Stripe and Mollie webhook-handler tests for cancel, fail and expire.
 */
#[CoversClass(ContractTerminationService::class)]
final class ContractTerminationServiceTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $repository;
    private ContractLinkedOrderUpdaterInterface&MockObject $orderUpdater;
    private ContractTerminationService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ContractRepositoryInterface::class);
        $this->orderUpdater = $this->createMock(ContractLinkedOrderUpdaterInterface::class);
        $this->service = new ContractTerminationService($this->repository, $this->orderUpdater);
    }

    public function testCancelEndsSavesAndMirrorsTheCancellationOnTheOrder(): void
    {
        $contract = $this->contract('pending', 'order-1');
        $contract->expects(self::once())->method('cancel')->with('customer left');
        $this->repository->expects(self::once())->method('save')->with($contract);
        $this->orderUpdater->expects(self::once())->method('markCancelled')->with('order-1');

        self::assertSame(TerminationOutcome::Terminated, $this->service->cancel($contract, 'customer left'));
    }

    public function testFailEndsSavesAndMirrorsTheFailureWithTheReason(): void
    {
        $contract = $this->contract('pending', 'order-2');
        $contract->expects(self::once())->method('fail')->with('declined');
        $this->repository->expects(self::once())->method('save')->with($contract);
        $this->orderUpdater->expects(self::once())->method('markFailed')->with('order-2', 'declined');

        self::assertSame(TerminationOutcome::Terminated, $this->service->fail($contract, 'declined'));
    }

    public function testExpireEndsSavesAndMirrorsAsCancelled(): void
    {
        $contract = $this->contract('pending', 'order-3');
        $contract->expects(self::once())->method('expire');
        $this->repository->expects(self::once())->method('save')->with($contract);
        $this->orderUpdater->expects(self::once())->method('markCancelled')->with('order-3');

        self::assertSame(TerminationOutcome::Terminated, $this->service->expire($contract));
    }

    public function testATerminalContractIsLeftAloneByEveryTransition(): void
    {
        $contract = $this->contract('cancelled', 'order-4');
        $contract->expects(self::never())->method('cancel');
        $contract->expects(self::never())->method('fail');
        $contract->expects(self::never())->method('expire');
        $this->repository->expects(self::never())->method('save');
        $this->orderUpdater->expects(self::never())->method('markCancelled');
        $this->orderUpdater->expects(self::never())->method('markFailed');

        self::assertSame(TerminationOutcome::AlreadyTerminal, $this->service->cancel($contract, 'r'));
        self::assertSame(TerminationOutcome::AlreadyTerminal, $this->service->fail($contract, 'r'));
        self::assertSame(TerminationOutcome::AlreadyTerminal, $this->service->expire($contract));
    }

    public function testAnExpiryOfACommittedContractIsReportedNotActedOn(): void
    {
        $contract = $this->contract('committed', 'order-5');
        $contract->expects(self::never())->method('expire');
        $this->repository->expects(self::never())->method('save');
        $this->orderUpdater->expects(self::never())->method('markCancelled');

        self::assertSame(TerminationOutcome::Committed, $this->service->expire($contract));
    }

    public function testNoOrderIsMirroredForAContractWithoutOneOrWithAnEmptyId(): void
    {
        $this->orderUpdater->expects(self::never())->method('markCancelled');
        $this->orderUpdater->expects(self::never())->method('markFailed');
        $this->repository->expects(self::exactly(4))->method('save');

        $this->service->cancel($this->contract('pending', null), 'r');
        $this->service->cancel($this->contract('pending', ''), 'r');
        $this->service->fail($this->contract('pending', null), 'r');
        $this->service->expire($this->contract('pending', null));
    }

    private function contract(string $state, ?string $orderId): PaymentContractInterface&MockObject
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn(ContractState::fromValue($state));
        $contract->method('getOrderId')->willReturn($orderId);

        return $contract;
    }
}
