<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Reconciliation;

use DateTimeImmutable;

/**
 * What a provider says about one payment: paid or not, and when the money moved.
 */
final class ProviderPaymentStatus
{
    private function __construct(
        public readonly bool $paid,
        public readonly string $status,
        public readonly ?DateTimeImmutable $paidAt,
    ) {
    }

    public static function notPaid(string $status): self
    {
        return new self(false, $status, null);
    }

    public static function paidAt(string $status, DateTimeImmutable $paidAt): self
    {
        return new self(true, $status, $paidAt);
    }

    /** The provider confirms the payment but gives no time: OXPAID must not be invented. */
    public static function paidWithoutTimestamp(string $status): self
    {
        return new self(true, $status, null);
    }
}
