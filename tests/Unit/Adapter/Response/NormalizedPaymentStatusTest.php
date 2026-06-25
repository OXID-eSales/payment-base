<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Adapter\Response;

use OxidEsales\PaymentBase\Adapter\Response\NormalizedPaymentStatus;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Sprint 114.10a (A2): Canonical normalized-status constants live in payment-base.
 *
 * RED: class does not exist yet.
 * GREEN: create NormalizedPaymentStatus with the 7 string constants.
 */
#[CoversClass(\OxidEsales\PaymentBase\Adapter\Response\NormalizedPaymentStatus::class)]
final class NormalizedPaymentStatusTest extends TestCase
{
    #[Test]
    public function pendingConstantHasCorrectValue(): void
    {
        $this->assertSame('pending', NormalizedPaymentStatus::PENDING);
    }

    #[Test]
    public function authorizedConstantHasCorrectValue(): void
    {
        $this->assertSame('authorized', NormalizedPaymentStatus::AUTHORIZED);
    }

    #[Test]
    public function capturedConstantHasCorrectValue(): void
    {
        $this->assertSame('captured', NormalizedPaymentStatus::CAPTURED);
    }

    #[Test]
    public function failedConstantHasCorrectValue(): void
    {
        $this->assertSame('failed', NormalizedPaymentStatus::FAILED);
    }

    #[Test]
    public function cancelledConstantHasCorrectValue(): void
    {
        $this->assertSame('cancelled', NormalizedPaymentStatus::CANCELLED);
    }

    #[Test]
    public function refundedConstantHasCorrectValue(): void
    {
        $this->assertSame('refunded', NormalizedPaymentStatus::REFUNDED);
    }

    #[Test]
    public function partiallyRefundedConstantHasCorrectValue(): void
    {
        $this->assertSame('partially_refunded', NormalizedPaymentStatus::PARTIALLY_REFUNDED);
    }

    #[Test]
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
