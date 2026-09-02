<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout;

use OxidEsales\PaymentBase\Adapter\ShopOrderServiceInterface;
use OxidEsales\PaymentBase\Checkout\PreviousCheckoutAttemptCleaner;
use OxidEsales\PaymentBase\Contract\ContractState;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Every provider creates its order BEFORE the shopper leaves for the PSP, so a
 * shopper who retries inside one session leaves a NOT_FINISHED order behind on
 * each attempt. Stripe had a cleanup for this; Mollie and PayPal never did, and
 * the orders piled up in the backend with no payment date.
 *
 * This is the provider-agnostic half of the fix.
 */
final class PreviousCheckoutAttemptCleanerTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contracts;
    private ShopOrderServiceInterface&MockObject $orders;
    private PreviousCheckoutAttemptCleaner $cleaner;

    protected function setUp(): void
    {
        $this->contracts = $this->createMock(ContractRepositoryInterface::class);
        $this->orders = $this->createMock(ShopOrderServiceInterface::class);
        $this->cleaner = new PreviousCheckoutAttemptCleaner($this->contracts, $this->orders);
    }

    private function contractInState(ContractState $state, ?string $orderId): PaymentContractInterface&MockObject
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn($state);
        $contract->method('getStateValue')->willReturn($state->getValue());
        $contract->method('getOrderId')->willReturn($orderId);

        return $contract;
    }

    public function testCancelsTheContractAndRemovesItsUnfinishedOrder(): void
    {
        $contract = $this->contractInState(ContractState::pending(), 'order-1');
        $this->contracts->method('findById')->with('contract-1')->willReturn($contract);

        $contract->expects($this->once())->method('cancel')->with('checkout_retry');
        $this->orders->expects($this->once())->method('deleteNotFinishedOrder')->with('order-1');
        $this->contracts->expects($this->once())->method('save')->with($contract);

        $this->assertTrue($this->cleaner->clean('contract-1'));
    }

    public function testLeavesACommittedAttemptAlone(): void
    {
        // The shopper paid. Cancelling here would storno a paid order.
        $contract = $this->contractInState(ContractState::committed(), 'order-1');
        $this->contracts->method('findById')->willReturn($contract);

        $contract->expects($this->never())->method('cancel');
        $this->orders->expects($this->never())->method('deleteNotFinishedOrder');
        $this->contracts->expects($this->never())->method('save');

        $this->assertFalse($this->cleaner->clean('contract-1'));
    }

    public function testLeavesATerminalAttemptAlone(): void
    {
        $contract = $this->contractInState(ContractState::cancelled(), 'order-1');
        $this->contracts->method('findById')->willReturn($contract);

        $contract->expects($this->never())->method('cancel');
        $this->orders->expects($this->never())->method('deleteNotFinishedOrder');

        $this->assertFalse($this->cleaner->clean('contract-1'));
    }

    public function testCancelsAContractThatNeverReachedAnOrder(): void
    {
        $contract = $this->contractInState(ContractState::draft(), null);
        $this->contracts->method('findById')->willReturn($contract);

        $this->orders->expects($this->never())->method('deleteNotFinishedOrder');
        $contract->expects($this->once())->method('cancel');

        $this->assertTrue($this->cleaner->clean('contract-1'));
    }

    public function testDoesNothingWithoutAPreviousAttempt(): void
    {
        $this->contracts->expects($this->never())->method('findById');

        $this->assertFalse($this->cleaner->clean(null));
    }

    public function testDoesNothingWhenTheContractIsGone(): void
    {
        $this->contracts->method('findById')->willReturn(null);

        $this->assertFalse($this->cleaner->clean('vanished'));
    }

    public function testAFailedOrderRemovalStillCancelsTheContract(): void
    {
        // Leaving the contract open would strand the next attempt behind a
        // cleanup that can never succeed.
        $contract = $this->contractInState(ContractState::pending(), 'order-1');
        $this->contracts->method('findById')->willReturn($contract);
        $this->orders->method('deleteNotFinishedOrder')
            ->willThrowException(new \RuntimeException('db gone'));

        $contract->expects($this->once())->method('cancel');

        $this->assertTrue($this->cleaner->clean('contract-1'));
    }
}
