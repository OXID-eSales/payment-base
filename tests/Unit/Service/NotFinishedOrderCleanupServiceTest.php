<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Service;

use InvalidArgumentException;
use OxidEsales\PaymentBase\Contract\ContractState;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\NotFinishedOrderRepositoryInterface;
use OxidEsales\PaymentBase\Service\NotFinishedOrderCleanupService;
use OxidEsales\PaymentBase\Service\NotFinishedOrderCleanupServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Garbage collection for orders the shop created just before handing the
 * customer to a PSP, where the customer never came back.
 *
 * Until this service existed the only collector in the system was a sweep
 * bolted onto the Stripe webhook controller, so a shop that received no
 * webhooks never collected anything at all.
 */
final class NotFinishedOrderCleanupServiceTest extends TestCase
{
    /** @var NotFinishedOrderRepositoryInterface&MockObject */
    private NotFinishedOrderRepositoryInterface $orders;
    /** @var ContractRepositoryInterface&MockObject */
    private ContractRepositoryInterface $contracts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orders = $this->createMock(NotFinishedOrderRepositoryInterface::class);
        $this->contracts = $this->createMock(ContractRepositoryInterface::class);
    }

    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(NotFinishedOrderCleanupServiceInterface::class, $this->service());
    }

    public function testCancelsEveryStaleOrderAndReleasesItsVouchers(): void
    {
        $this->orders->method('findStaleNotFinishedOrderIds')->willReturn(['order-a', 'order-b']);
        $this->orders->expects($this->exactly(2))->method('cancelOrder')->willReturn(true);
        $this->orders->expects($this->exactly(2))->method('releaseVouchers')->willReturn(1);
        $this->contracts->method('findByOrderId')->willReturn(null);

        $result = $this->service()->cleanup(7);

        $this->assertSame(2, $result->scanned);
        $this->assertSame(2, $result->ordersCancelled);
        $this->assertSame(2, $result->vouchersReleased);
        $this->assertSame(0, $result->contractsCancelled);
        $this->assertFalse($result->dryRun);
    }

    /**
     * The 72 NOT_FINISHED orders on the dev shop that no contract points at
     * are the whole reason this collector is keyed on the order table rather
     * than on the contract table.
     */
    public function testCleansOrdersThatNoContractPointsAt(): void
    {
        $this->orders->method('findStaleNotFinishedOrderIds')->willReturn(['orphan']);
        $this->orders->method('cancelOrder')->willReturn(true);
        $this->orders->method('releaseVouchers')->willReturn(0);
        $this->contracts->expects($this->once())->method('findByOrderId')->with('orphan')->willReturn(null);
        $this->contracts->expects($this->never())->method('save');

        $this->assertSame(1, $this->service()->cleanup(7)->ordersCancelled);
    }

    public function testCancelsTheLinkedContractAlongsideTheOrder(): void
    {
        $contract = $this->contractInState(ContractState::pending());
        $contract->expects($this->once())->method('cancel');

        $this->orders->method('findStaleNotFinishedOrderIds')->willReturn(['order-a']);
        $this->orders->method('cancelOrder')->willReturn(true);
        $this->orders->method('releaseVouchers')->willReturn(0);
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->contracts->expects($this->once())->method('save')->with($contract);

        $this->assertSame(1, $this->service()->cleanup(7)->contractsCancelled);
    }

    /**
     * A committed or fulfilled contract means money moved. Touching its
     * contract would rewrite settled payment history, so the order is still
     * stornoed but the contract is left exactly as it is.
     */
    public function testLeavesASettledContractAlone(): void
    {
        $contract = $this->contractInState(ContractState::fulfilled());
        $contract->expects($this->never())->method('cancel');

        $this->orders->method('findStaleNotFinishedOrderIds')->willReturn(['order-a']);
        $this->orders->method('cancelOrder')->willReturn(true);
        $this->orders->method('releaseVouchers')->willReturn(0);
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->contracts->expects($this->never())->method('save');

        $result = $this->service()->cleanup(7);

        $this->assertSame(1, $result->ordersCancelled);
        $this->assertSame(0, $result->contractsCancelled);
    }

    public function testDryRunReportsTheCandidatesAndWritesNothing(): void
    {
        $this->orders->method('findStaleNotFinishedOrderIds')->willReturn(['a', 'b', 'c']);
        $this->orders->expects($this->never())->method('cancelOrder');
        $this->orders->expects($this->never())->method('releaseVouchers');
        $this->contracts->expects($this->never())->method('save');

        $result = $this->service()->cleanup(7, dryRun: true);

        $this->assertSame(3, $result->scanned);
        $this->assertSame(0, $result->ordersCancelled);
        $this->assertTrue($result->dryRun);
    }

    /**
     * "Older than 0 days" is every unfinished order in the shop, including
     * the checkout in progress one second ago. Refuse it at the boundary.
     */
    public function testRefusesAPeriodBelowOneDay(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service()->cleanup(0);
    }

    public function testPassesTheShopAndLimitThroughToTheQuery(): void
    {
        $this->orders
            ->expects($this->once())
            ->method('findStaleNotFinishedOrderIds')
            ->with(30, 2, 100)
            ->willReturn([]);

        $this->service()->cleanup(30, shopId: 2, limit: 100);
    }

    /**
     * One unreadable order must not abort the batch — otherwise a single bad
     * row keeps every later order uncollected forever.
     */
    public function testOneFailingOrderDoesNotAbortTheBatch(): void
    {
        $this->orders->method('findStaleNotFinishedOrderIds')->willReturn(['bad', 'good']);
        $this->orders->method('releaseVouchers')->willReturn(0);
        $this->orders
            ->method('cancelOrder')
            ->willReturnCallback(static function (string $orderId): bool {
                if ($orderId === 'bad') {
                    throw new RuntimeException('row is locked');
                }

                return true;
            });
        $this->contracts->method('findByOrderId')->willReturn(null);

        $result = $this->service()->cleanup(7);

        $this->assertSame(1, $result->ordersCancelled);
        $this->assertSame(1, $result->failed);
    }

    /**
     * cancelOrder() answers false when the row no longer qualifies (someone
     * finished it between the query and the write). That is not a failure,
     * but it must not be counted as a cancellation either.
     */
    public function testAnOrderThatNoLongerQualifiesIsNeitherCancelledNorFailed(): void
    {
        $this->orders->method('findStaleNotFinishedOrderIds')->willReturn(['raced']);
        $this->orders->method('releaseVouchers')->willReturn(0);
        $this->orders->method('cancelOrder')->willReturn(false);
        $this->contracts->method('findByOrderId')->willReturn(null);

        $result = $this->service()->cleanup(7);

        $this->assertSame(0, $result->ordersCancelled);
        $this->assertSame(0, $result->failed);
    }

    private function service(): NotFinishedOrderCleanupService
    {
        return new NotFinishedOrderCleanupService($this->orders, $this->contracts);
    }

    /**
     * @return PaymentContractInterface&MockObject
     */
    private function contractInState(ContractState $state): PaymentContractInterface
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract-1');
        $contract->method('getState')->willReturn($state);
        $contract->method('getStateValue')->willReturn($state->getValue());

        return $contract;
    }
}
