<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Math\Money;

/**
 * Currency-aware major↔minor unit converter for payment amounts.
 *
 * PSPs expect amounts in the smallest currency unit (minor units):
 * - 2-decimal currencies (EUR, USD, GBP, …): amount in cents     → 19.99 EUR = 1999
 * - 0-decimal currencies (JPY, KRW, …):       amount is the unit  → ¥1000    = 1000
 * - 3-decimal currencies (BHD, KWD, …):       amount in 1/1000s  → 1.234 BHD = 1234
 *
 * Canonical home for the conversion previously hand-coded as `(int) round($x * 100)`
 * across the MCP response formatters (which were currency-blind and wrong for
 * JPY/BHD) and duplicated by the Stripe module's AmountConverter. See report
 * 20260622/reports/02-floating-point-math-code-review.md §5.2.
 *
 * Static API: pure function, no side-effects, no swappable dependency — an
 * interface would add a test-double layer with no benefit (YAGNI). Test it by
 * calling the static methods directly.
 *
 * Rounding: toMinorUnits uses (int) round() to avoid IEEE-754 truncation drift
 * (e.g. 19.99 * 100 = 1998.9999… in float → naive (int) gives 1998, not 1999).
 *
 * Unknown or empty currency defaults to 2 decimals (safe, shop-agnostic
 * fallback; do NOT hardcode 'EUR').
 */
final class MinorUnitConverter
{
    /**
     * Zero-decimal currencies per the published ISO-4217 / PSP lists.
     * https://stripe.com/docs/currencies#zero-decimal
     *
     * @var array<string>
     */
    private const ZERO_DECIMAL_CURRENCIES = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW',
        'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV',
        'XAF', 'XOF', 'XPF',
    ];

    /**
     * Three-decimal currencies per the published ISO-4217 / PSP lists.
     * https://stripe.com/docs/currencies#three-decimal
     *
     * @var array<string>
     */
    private const THREE_DECIMAL_CURRENCIES = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];

    /**
     * Number of decimal places (exponent) for a given ISO-4217 currency code.
     *
     * Returns 0 for zero-decimal currencies, 3 for three-decimal currencies,
     * and 2 for everything else (the default, not hard-wired to 'EUR').
     * Matching is case-insensitive.
     */
    public static function decimalsFor(string $currency): int
    {
        $upper = strtoupper($currency);

        if (in_array($upper, self::ZERO_DECIMAL_CURRENCIES, true)) {
            return 0;
        }

        if (in_array($upper, self::THREE_DECIMAL_CURRENCIES, true)) {
            return 3;
        }

        return 2;
    }

    /**
     * Convert a major-unit amount to minor units (integer).
     *
     * Uses (int) round() — not truncation — to avoid IEEE-754 drift:
     *   (int) (19.99 * 100)        → 1998 (WRONG)
     *   (int) round(19.99 * 100)   → 1999 (CORRECT)
     */
    public static function toMinorUnits(float $major, string $currency): int
    {
        $multiplier = 10 ** self::decimalsFor($currency);

        return (int) round($major * $multiplier);
    }

    /**
     * Convert minor units (integer) to a major-unit float.
     */
    public static function toMajorUnits(int $minor, string $currency): float
    {
        $divisor = 10 ** self::decimalsFor($currency);

        return $minor / $divisor;
    }
}
