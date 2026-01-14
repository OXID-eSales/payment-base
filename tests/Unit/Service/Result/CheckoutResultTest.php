<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Service\Result;

use PHPUnit\Framework\TestCase;
use OxidEsales\PaymentComponent\Service\Result\CheckoutResult;

class CheckoutResultTest extends TestCase
{
    public function testSuccess_CreatesSuccessfulResult(): void
    {
        $result = CheckoutResult::success('contract_123');

        $this->assertTrue($result->isSuccess());
        $this->assertEquals('contract_123', $result->getContractId());
        $this->assertNull($result->getErrorMessage());
        $this->assertNull($result->getErrorCode());
    }

    public function testFailure_CreatesFailedResult(): void
    {
        $result = CheckoutResult::failure('Basket is empty', 'EMPTY_BASKET');

        $this->assertFalse($result->isSuccess());
        $this->assertNull($result->getContractId());
        $this->assertEquals('Basket is empty', $result->getErrorMessage());
        $this->assertEquals('EMPTY_BASKET', $result->getErrorCode());
    }

    public function testFailure_WithoutErrorCode_AllowsNullCode(): void
    {
        $result = CheckoutResult::failure('Unknown error');

        $this->assertFalse($result->isSuccess());
        $this->assertEquals('Unknown error', $result->getErrorMessage());
        $this->assertNull($result->getErrorCode());
    }

    public function testIsImmutable(): void
    {
        $result = CheckoutResult::success('contract_123');

        // Verify readonly - this should not allow modification
        $reflection = new \ReflectionClass($result);
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testSuccess_WithDifferentContractId_StoresCorrectly(): void
    {
        $result = CheckoutResult::success('contract_abc_456');

        $this->assertEquals('contract_abc_456', $result->getContractId());
    }

    public function testFailure_WithBothErrorMessageAndCode_StoresBoth(): void
    {
        $result = CheckoutResult::failure('Payment declined', 'PAYMENT_DECLINED');

        $this->assertEquals('Payment declined', $result->getErrorMessage());
        $this->assertEquals('PAYMENT_DECLINED', $result->getErrorCode());
    }
}
