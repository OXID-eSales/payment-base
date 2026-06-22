<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Math\Money;

/**
 * Currency-amount comparison helpers with a single shared tolerance.
 *
 * Money amounts are IEEE-754 floats in the OXID shop domain, so direct `==`
 * / `<=` / `>=` comparisons accumulate binary-rounding noise. Every comparison
 * here absorbs that noise with one half-cent epsilon, replacing the three
 * private `0.005` constants that previously lived in CaptureRefundTracker,
 * RefundIntentHandler and the Stripe CaptureService. See report
 * 20260622/reports/02-floating-point-math-code-review.md §5.3.
 *
 * Half a cent is tight enough never to collapse a real partial amount into a
 * full one, loose enough to absorb drift from accumulated float arithmetic.
 */
final class Money
{
    /** Half a cent — the shared float-equality tolerance for currency amounts. */
    public const HALF_CENT_EPSILON = 0.005;

    /** True when $a and $b are equal within the half-cent tolerance. */
    public static function equals(float $a, float $b): bool
    {
        return abs($a - $b) < self::HALF_CENT_EPSILON;
    }

    /** True when $a is strictly greater than $b beyond the tolerance. */
    public static function greaterThan(float $a, float $b): bool
    {
        return $a > $b + self::HALF_CENT_EPSILON;
    }

    /** True when $a is at least $b, allowing a sub-tolerance shortfall. */
    public static function atLeast(float $a, float $b): bool
    {
        return $a >= $b - self::HALF_CENT_EPSILON;
    }

    /** True when $a is at most $b, allowing a sub-tolerance overshoot. */
    public static function atMost(float $a, float $b): bool
    {
        return $a <= $b + self::HALF_CENT_EPSILON;
    }
}
