<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Adapter\Response;

use OxidEsales\PaymentBase\Adapter\Response\NormalizedPaymentStatus;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 114.10a (A2): Canonical normalized-status constants live in payment-base.
 *
 * RED: class does not exist yet.
 * GREEN: create NormalizedPaymentStatus with the 7 string constants.
 *
 * @covers \OxidEsales\PaymentBase\Adapter\Response\NormalizedPaymentStatus
 */
final class NormalizedPaymentStatusTest extends TestCase
{
    /**
     * @test
     */
    public function pendingConstantHasCorrectValue(): void
    {
        $this->assertSame('pending', NormalizedPaymentStatus::PENDING);
    }

    /**
     * @test
     */
    public function authorizedConstantHasCorrectValue(): void
    {
        $this->assertSame('authorized', NormalizedPaymentStatus::AUTHORIZED);
    }

    /**
     * @test
     */
    public function capturedConstantHasCorrectValue(): void
    {
        $this->assertSame('captured', NormalizedPaymentStatus::CAPTURED);
    }

    /**
     * @test
     */
    public function failedConstantHasCorrectValue(): void
    {
        $this->assertSame('failed', NormalizedPaymentStatus::FAILED);
    }

    /**
     * @test
     */
    public function cancelledConstantHasCorrectValue(): void
    {
        $this->assertSame('cancelled', NormalizedPaymentStatus::CANCELLED);
    }

    /**
     * @test
     */
    public function refundedConstantHasCorrectValue(): void
    {
        $this->assertSame('refunded', NormalizedPaymentStatus::REFUNDED);
    }

    /**
     * @test
     */
    public function partiallyRefundedConstantHasCorrectValue(): void
    {
        $this->assertSame('partially_refunded', NormalizedPaymentStatus::PARTIALLY_REFUNDED);
    }

    /**
     * @test
     */
    public function allConstantsAreStrings(): void
    {
        $constants = [
            NormalizedPaymentStatus::PENDING,
            NormalizedPaymentStatus::AUTHORIZED,
            NormalizedPaymentStatus::CAPTURED,
            NormalizedPaymentStatus::FAILED,
            NormalizedPaymentStatus::CANCELLED,
            NormalizedPaymentStatus::REFUNDED,
            NormalizedPaymentStatus::PARTIALLY_REFUNDED,
        ];

        foreach ($constants as $constant) {
            $this->assertIsString($constant);
        }
    }
}
