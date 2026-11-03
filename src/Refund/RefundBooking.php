<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Refund;

/**
 * What booking a provider refund did: booked now, or already booked under the same
 * provider refund id (nothing changed). Carries the contract's refunded total after
 * the call either way.
 */
final readonly class RefundBooking
{
    private function __construct(
        public bool $booked,
        public float $refundedTotal,
    ) {
    }

    public static function booked(float $refundedTotal): self
    {
        return new self(true, $refundedTotal);
    }

    public static function duplicate(float $refundedTotal): self
    {
        return new self(false, $refundedTotal);
    }
}
