<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter;

use RuntimeException;

/**
 * Resolves the ISO-4217 code of an OXID currency object, or fails.
 *
 * Sprint 133 · Story 7 (F7): four sites resolved a currency as
 * `$currency->name ?? 'EUR'` — `OxidShopAdapter`, `OxidShopOrderService`,
 * `PaymentController` and `StripeReturnResolver`. On a CHF- or USD-only shop
 * whose currency object is unavailable (misconfigured aCurrencies, a currency
 * deleted mid-session) that created a PaymentIntent in EUR carrying the CHF
 * amount: CHF 100.00 charged as EUR 100.00, accepted by Stripe, discovered only
 * in reconciliation.
 *
 * It also contradicted the rule payment-base states in writing
 * ({@see \OxidEsales\PaymentBase\Math\Money\MinorUnitConverter}): "Unknown or
 * empty currency defaults to 2 decimals (safe, shop-agnostic fallback; do NOT
 * hardcode 'EUR')".
 *
 * A payment must never guess its own currency, so this throws instead.
 * Static pure function, no state, no swappable dependency (YAGNI) — the same
 * idiom as {@see AmountConverter} and CapturableAmount.
 *
 * @since 2026-09-01 (moved from the Stripe module when payment-base took ownership
 *        of order finalization)
 */
final class ShopCurrency
{
    /**
     * @param object|null $currency OXID currency object (stdClass with ->name)
     * @param string $context Where the lookup happened, for the error message
     *
     * @throws RuntimeException when no usable currency code is present
     */
    public static function nameOf(?object $currency, string $context): string
    {
        /** @var mixed $name */
        $name = $currency->name ?? null;

        if (is_string($name) && $name !== '') {
            return $name;
        }

        throw new RuntimeException(sprintf(
            'Unable to resolve the shop currency (%s). Refusing to assume a currency for a payment.',
            $context
        ));
    }

    /**
     * Same resolution, but for display-only callers that must not break the
     * page when the currency is unknown. Never invents a code.
     */
    public static function nameOrEmpty(?object $currency): string
    {
        /** @var mixed $name */
        $name = $currency->name ?? null;

        return is_string($name) ? $name : '';
    }
}
