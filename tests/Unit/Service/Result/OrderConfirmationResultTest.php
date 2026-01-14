<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Service\Result;

use PHPUnit\Framework\TestCase;
use OxidEsales\PaymentComponent\Service\Result\OrderConfirmationResult;

class OrderConfirmationResultTest extends TestCase
{
    public function testSuccess_CreatesSuccessfulResult(): void
    {
        $result = OrderConfirmationResult::success(OrderConfirmationResult::STATE_COMMITTED);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals('COMMITTED', $result->getContractState());
        $this->assertNull($result->getErrorMessage());
    }

    public function testFailure_CreatesFailedResult(): void
    {
        $result = OrderConfirmationResult::failure('Contract not found');

        $this->assertFalse($result->isSuccess());
        $this->assertEquals('Contract not found', $result->getErrorMessage());
        $this->assertEquals('FAILED', $result->getContractState());
    }

    public function testIsAwaitingPaymentConfirmation_WithCommittedState_ReturnsTrue(): void
    {
        $result = OrderConfirmationResult::success(OrderConfirmationResult::STATE_COMMITTED);

        $this->assertTrue($result->isAwaitingPaymentConfirmation());
        $this->assertFalse($result->isFullyCompleted());
    }

    public function testIsFullyCompleted_WithFulfilledState_ReturnsTrue(): void
    {
        $result = OrderConfirmationResult::success(OrderConfirmationResult::STATE_FULFILLED);

        $this->assertTrue($result->isFullyCompleted());
        $this->assertFalse($result->isAwaitingPaymentConfirmation());
    }

    public function testIsAwaitingPaymentConfirmation_WithPendingState_ReturnsFalse(): void
    {
        $result = OrderConfirmationResult::success(OrderConfirmationResult::STATE_PENDING);

        $this->assertFalse($result->isAwaitingPaymentConfirmation());
        $this->assertFalse($result->isFullyCompleted());
    }

    public function testIsImmutable(): void
    {
        $result = OrderConfirmationResult::success(OrderConfirmationResult::STATE_COMMITTED);

        $reflection = new \ReflectionClass($result);
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testFailure_WithCustomState_UsesProvidedState(): void
    {
        $result = OrderConfirmationResult::failure('Payment timeout', OrderConfirmationResult::STATE_PENDING);

        $this->assertFalse($result->isSuccess());
        $this->assertEquals('PENDING', $result->getContractState());
        $this->assertEquals('Payment timeout', $result->getErrorMessage());
    }

    public function testStateConstants_AreCorrect(): void
    {
        $this->assertEquals('PENDING', OrderConfirmationResult::STATE_PENDING);
        $this->assertEquals('COMMITTED', OrderConfirmationResult::STATE_COMMITTED);
        $this->assertEquals('FULFILLED', OrderConfirmationResult::STATE_FULFILLED);
        $this->assertEquals('FAILED', OrderConfirmationResult::STATE_FAILED);
    }
}
