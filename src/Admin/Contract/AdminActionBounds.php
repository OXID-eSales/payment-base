<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Admin\Contract;

/**
 * What a provider says may still be captured and refunded, in major units, from its own live source (Mollie's
 * multi-capture remainder, its refundable amount after Dashboard refunds). A bound the provider leaves out (null) keeps
 * payment-base's own number; a negative or not-a-number bound is the closed one (0).
 *
 * @since 1.7.0
 */
final readonly class AdminActionBounds
{
    public ?float $capturable;

    public ?float $refundable;

    public function __construct(?float $capturable = null, ?float $refundable = null, public bool $available = true)
    {
        $this->capturable = self::usable($capturable);
        $this->refundable = self::usable($refundable);
    }

    /** The provider's bound could not be read: nothing is offered and a write is refused (fail closed). */
    public static function unavailable(): self
    {
        return new self(0.0, 0.0, false);
    }

    private static function usable(?float $bound): ?float
    {
        if ($bound === null) {
            return null;
        }

        return is_nan($bound) ? 0.0 : max(0.0, $bound);
    }
}
