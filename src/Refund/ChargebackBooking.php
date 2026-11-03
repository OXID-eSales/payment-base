<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Refund;

/**
 * What booking a provider's chargeback total did: the change of the total (negative for a reversal), or
 * nothing when the provider reported the total already booked.
 */
final readonly class ChargebackBooking
{
    public function __construct(
        public bool $booked,
        public float $delta,
        public float $chargedBackTotal,
    ) {
    }
}
