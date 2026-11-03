<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Service;

use InvalidArgumentException;
use OxidEsales\PaymentBase\Adapter\OrderRestockInterface;
use OxidEsales\PaymentBase\Checkout\Attempt\PaymentAttemptClosersInterface;
use OxidEsales\PaymentBase\Contract\AttemptClosure;
use OxidEsales\PaymentBase\Contract\ContractState;
use OxidEsales\PaymentBase\LateMoney\ProviderPaymentGatewayInterface;
use OxidEsales\PaymentBase\LateMoney\ProviderPaymentGatewaysInterface;
use OxidEsales\PaymentBase\LateMoney\ProviderPaymentState;
use OxidEsales\PaymentBase\LateMoney\ProviderPaymentStatus;
use OxidEsales\PaymentBase\Service\Commit\CommitOutcome;
use OxidEsales\PaymentBase\Service\Commit\ContractCommitServiceInterface;
use OxidEsales\PaymentBase\Service\Commit\PaymentConfirmation;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Logging\ThrowableSummaryInterface;
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
    /** @var OrderRestockInterface&MockObject */
    private OrderRestockInterface $restock;
    /** @var PaymentAttemptClosersInterface&MockObject */
    private PaymentAttemptClosersInterface $closers;
    /** @var ProviderPaymentGatewayInterface&MockObject */
    private ProviderPaymentGatewayInterface $gateway;
    /** @var ContractCommitServiceInterface&MockObject */
    private ContractCommitServiceInterface $commits;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orders = $this->createMock(NotFinishedOrderRepositoryInterface::class);
        $this->contracts = $this->createMock(ContractRepositoryInterface::class);
        $this->restock = $this->createMock(OrderRestockInterface::class);
        $this->closers = $this->createMock(PaymentAttemptClosersInterface::class);
        $this->closers->method('close')->willReturn(AttemptClosure::Closed);
        $this->gateway = $this->createMock(ProviderPaymentGatewayInterface::class);
        $this->commits = $this->createMock(ContractCommitServiceInterface::class);
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
        $restocked = [];
        $this->restock->expects($this->exactly(2))->method('restock')
            ->willReturnCallback(static function (string $orderId) use (&$restocked): void {
                $restocked[] = $orderId;
            });
        $this->contracts->method('findByOrderId')->willReturn(null);

        $result = $this->service()->cleanup(7);

        $this->assertSame(2, $result->scanned);
        $this->assertSame(2, $result->ordersCancelled);
        $this->assertSame(2, $result->vouchersReleased);
        $this->assertSame(0, $result->contractsCancelled);
        $this->assertFalse($result->dryRun);
        $this->assertSame(['order-a', 'order-b'], $restocked, 'OPC-175 / S4 (G9): the stock comes back');
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
        $this->restock->expects($this->never())->method('restock');
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
        $this->restock->expects($this->never())->method('restock');
        $this->contracts->method('findByOrderId')->willReturn(null);

        $result = $this->service()->cleanup(7);

        $this->assertSame(0, $result->ordersCancelled);
        $this->assertSame(0, $result->failed);
    }

    /**
     * VR-23 T1: the nightly cleanup used to cancel the contract of an attempt the customer had in fact paid, and
     * the provider's paid webhook then met a cancelled contract (money taken, no order). The provider is asked
     * first; what is paid is committed, not cancelled.
     */
    public function testPaidAtPspIsCommittedNotCancelled(): void
    {
        $contract = $this->openContract('pending', 'tr_1');
        $this->orders->method('findStaleNotFinishedOrderIds')->willReturn(['order-a']);
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->closers = $this->createMock(PaymentAttemptClosersInterface::class);
        $this->closers->expects($this->once())->method('close')->with($contract)->willReturn(AttemptClosure::Paid);
        $this->gatewayAnswers(new ProviderPaymentStatus(ProviderPaymentState::Paid, 'pay_1', 49.9, 'EUR'));
        $this->commits->expects($this->once())->method('commit')
            ->with($this->equalTo(new PaymentConfirmation(
                'contract-1',
                'mollie',
                'pay_1',
                'tr_1',
                49.9,
                'EUR',
                false,
                'cleanup'
            )))
            ->willReturn(CommitOutcome::committed('order-a'));
        $this->orders->expects($this->never())->method('cancelOrder');
        $this->orders->expects($this->never())->method('releaseVouchers');
        $this->restock->expects($this->never())->method('restock');
        $contract->expects($this->never())->method('cancel');

        $result = $this->service()->cleanup(7);

        $this->assertSame(1, $result->contractsCommitted);
        $this->assertSame(0, $result->ordersCancelled);
        $this->assertSame(0, $result->contractsCancelled);
        $this->assertSame(0, $result->failed);
    }

    public function testAnAuthorizationAtPspIsCommittedAsAwaitingCapture(): void
    {
        $contract = $this->openContract('pending', 'tr_1');
        $this->orders->method('findStaleNotFinishedOrderIds')->willReturn(['order-a']);
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->closers = $this->createMock(PaymentAttemptClosersInterface::class);
        $this->closers->method('close')->willReturn(AttemptClosure::Paid);
        $this->gatewayAnswers(new ProviderPaymentStatus(ProviderPaymentState::Authorized, 'pay_1', 49.9, 'EUR'));
        $this->commits->expects($this->once())->method('commit')
            ->with($this->callback(static fn (PaymentConfirmation $c): bool => $c->requiresCapture === true))
            ->willReturn(CommitOutcome::committed('order-a'));

        $this->assertSame(1, $this->service()->cleanup(7)->contractsCommitted);
    }

    public function testACommitTheContractRefusesLeavesOrderAndContractAndIsReportedAsFailed(): void
    {
        $contract = $this->openContract('pending', 'tr_1');
        $this->orders->method('findStaleNotFinishedOrderIds')->willReturn(['order-a']);
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->closers = $this->createMock(PaymentAttemptClosersInterface::class);
        $this->closers->method('close')->willReturn(AttemptClosure::Paid);
        $this->gatewayAnswers(new ProviderPaymentStatus(ProviderPaymentState::Paid, 'pay_1', 10.0, 'EUR'));
        $this->commits->method('commit')->willReturn(CommitOutcome::refused('amount_mismatch'));
        $this->orders->expects($this->never())->method('cancelOrder');
        $contract->expects($this->never())->method('cancel');

        $result = $this->service()->cleanup(7);

        $this->assertSame(0, $result->contractsCommitted);
        $this->assertSame(1, $result->failed, 'paid money without an order is for the operator to see');
    }

    public function testAPaymentPageThatIsClosedOrOpenIsCancelledLocallyAfterTheProviderWasAsked(): void
    {
        $this->orders->method('findStaleNotFinishedOrderIds')->willReturn(['order-a', 'order-b']);
        $this->orders->method('cancelOrder')->willReturn(true);
        $this->orders->method('releaseVouchers')->willReturn(0);
        $first = $this->openContract('pending', 'tr_1');
        $second = $this->openContract('not_finished', 'tr_2');
        $first->expects($this->once())->method('cancel');
        $second->expects($this->once())->method('cancel');
        $this->contracts->method('findByOrderId')->willReturnOnConsecutiveCalls($first, $second);
        $this->closers = $this->createMock(PaymentAttemptClosersInterface::class);
        $this->closers->expects($this->exactly(2))->method('close')
            ->willReturnOnConsecutiveCalls(AttemptClosure::Closed, AttemptClosure::Open);
        $this->commits->expects($this->never())->method('commit');

        $this->assertSame(2, $this->service()->cleanup(7)->contractsCancelled);
    }

    /**
     * The provider could not be asked: nothing is cancelled on a guess, and the order is collected by a later run.
     */
    public function testAProviderThatCannotBeAskedLeavesOrderAndContractForTheNextRun(): void
    {
        $contract = $this->openContract('pending', 'tr_1');
        $this->orders->method('findStaleNotFinishedOrderIds')->willReturn(['order-a']);
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->closers = $this->createMock(PaymentAttemptClosersInterface::class);
        $this->closers->method('close')->willThrowException(new RuntimeException('psp unreachable'));
        $this->orders->expects($this->never())->method('cancelOrder');
        $contract->expects($this->never())->method('cancel');

        $this->assertSame(1, $this->service()->cleanup(7)->failed);
    }

    public function testASettledContractIsNotAskedAboutAtTheProvider(): void
    {
        $this->orders->method('findStaleNotFinishedOrderIds')->willReturn(['order-a']);
        $this->orders->method('cancelOrder')->willReturn(true);
        $this->orders->method('releaseVouchers')->willReturn(0);
        $this->contracts->method('findByOrderId')->willReturn($this->contractInState(ContractState::committed()));
        $this->closers = $this->createMock(PaymentAttemptClosersInterface::class);
        $this->closers->expects($this->never())->method('close');

        $this->service()->cleanup(7);
    }

    public function testADryRunDoesNotAskTheProvider(): void
    {
        $this->orders->method('findStaleNotFinishedOrderIds')->willReturn(['order-a']);
        $this->closers = $this->createMock(PaymentAttemptClosersInterface::class);
        $this->closers->expects($this->never())->method('close');

        $this->service()->cleanup(7, dryRun: true);
    }

    private function gatewayAnswers(ProviderPaymentStatus $status): void
    {
        $this->gateway->expects($this->once())->method('statusOf')->with('tr_1')->willReturn($status);
    }

    /**
     * @return PaymentContractInterface&MockObject
     */
    private function openContract(string $state, string $providerOrderId): PaymentContractInterface
    {
        $contract = $this->contractInState(ContractState::fromValue($state));
        $contract->method('getProvider')->willReturn('mollie');
        $contract->method('getProviderOrderId')->willReturn($providerOrderId);
        $contract->method('getAmount')->willReturn(49.9);
        $contract->method('getCurrency')->willReturn('EUR');

        return $contract;
    }

    private function service(): NotFinishedOrderCleanupService
    {
        $gateways = $this->createMock(ProviderPaymentGatewaysInterface::class);
        $gateways->method('forContract')->willReturn($this->gateway);

        return new NotFinishedOrderCleanupService(
            $this->orders,
            $this->contracts,
            $this->restock,
            $this->closers,
            $gateways,
            $this->commits,
            $this->createStub(ThrowableSummaryInterface::class),
        );
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
