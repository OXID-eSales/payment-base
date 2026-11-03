<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Payment;

/**
 * What booking a provider capture did: booked now, or already booked under the same provider capture id (nothing
 * changed). Carries the contract's captured total after the call either way.
 *
 * @since 1.7.0
 */
final readonly class CaptureBooking
{
    private function __construct(public bool $booked, public float $capturedTotal)
    {
    }

    public static function booked(float $capturedTotal): self
    {
        return new self(true, $capturedTotal);
    }

    public static function duplicate(float $capturedTotal): self
    {
        return new self(false, $capturedTotal);
    }
}
