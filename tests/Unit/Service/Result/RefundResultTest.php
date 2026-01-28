<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Service\Result;

use DateTimeImmutable;
use OxidEsales\PaymentComponent\Service\Result\RefundResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RefundResult DTO.
 *
 * Sprint 25: Tests for unified RefundResult with success/failure pattern.
 *
 * @covers \OxidEsales\PaymentComponent\Service\Result\RefundResult
 */
class RefundResultTest extends TestCase
{
    public function testSuccessFactoryMethod(): void
    {
        $result = RefundResult::success('re_123', 2550, 'eur', 'succeeded');

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('re_123', $result->getRefundId());
        $this->assertSame(2550, $result->getRefundedAmountCents());
        $this->assertSame(25.50, $result->getRefundedAmount());
        $this->assertSame('eur', $result->getCurrency());
        $this->assertSame('succeeded', $result->getStatus());
        $this->assertNull($result->getErrorMessage());
        $this->assertNull($result->getErrorCode());
    }

    public function testCreateFactoryMethodForAbstractServices(): void
    {
        $refundedAt = new DateTimeImmutable('2026-01-28 12:00:00');
        $result = RefundResult::create(
            refundId: 're_456',
            amountRefunded: 50.00,
            currency: 'usd',
            totalRefunded: 100.00,
            availableForRefund: 50.00,
            refundedAt: $refundedAt,
            providerData: ['key' => 'value']
        );

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('re_456', $result->getRefundId());
        $this->assertSame(50.00, $result->getAmountRefunded());
        $this->assertSame('usd', $result->getCurrency());
        $this->assertSame(100.00, $result->getTotalRefunded());
        $this->assertSame(50.00, $result->getAvailableForRefund());
        $this->assertSame($refundedAt, $result->getRefundedAt());
        $this->assertSame(['key' => 'value'], $result->getProviderData());
        $this->assertSame('succeeded', $result->getStatus());
    }

    public function testFailureFactoryMethod(): void
    {
        $result = RefundResult::failure('Charge already refunded', 'charge_already_refunded');

        $this->assertFalse($result->isSuccessful());
        $this->assertNull($result->getRefundId());
        $this->assertNull($result->getRefundedAmountCents());
        $this->assertNull($result->getRefundedAmount());
        $this->assertNull($result->getCurrency());
        $this->assertSame('failed', $result->getStatus());
        $this->assertSame('Charge already refunded', $result->getErrorMessage());
        $this->assertSame('charge_already_refunded', $result->getErrorCode());
    }

    public function testFailureWithoutErrorCode(): void
    {
        $result = RefundResult::failure('Unknown error');

        $this->assertFalse($result->isSuccessful());
        $this->assertSame('Unknown error', $result->getErrorMessage());
        $this->assertNull($result->getErrorCode());
    }

    public function testSuccessWithPendingStatus(): void
    {
        $result = RefundResult::success('re_pending', 1000, 'usd', 'pending');

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('pending', $result->getStatus());
    }

    public function testGetRefundedAmountCentsConversion(): void
    {
        $refundedAt = new DateTimeImmutable();
        $result = RefundResult::create(
            refundId: 're_test',
            amountRefunded: 99.99,
            currency: 'eur',
            totalRefunded: 99.99,
            availableForRefund: 0.01,
            refundedAt: $refundedAt
        );

        $this->assertSame(9999, $result->getRefundedAmountCents());
    }

    public function testSuccessResultIsImmutable(): void
    {
        $result1 = RefundResult::success('re_1', 1000, 'eur', 'succeeded');
        $result2 = RefundResult::success('re_2', 2000, 'usd', 'pending');

        $this->assertNotSame($result1->getRefundId(), $result2->getRefundId());
        $this->assertNotSame($result1->getRefundedAmountCents(), $result2->getRefundedAmountCents());
    }

    public function testFailedResultIsImmutable(): void
    {
        $result1 = RefundResult::failure('Error 1', 'code_1');
        $result2 = RefundResult::failure('Error 2', 'code_2');

        $this->assertNotSame($result1->getErrorMessage(), $result2->getErrorMessage());
        $this->assertNotSame($result1->getErrorCode(), $result2->getErrorCode());
    }
}
