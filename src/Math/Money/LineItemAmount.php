<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Math\Money;

/**
 * Per-line monetary totals for a single basket line (gross, net, VAT).
 *
 * Extracted from ContractService::extractProductItems() so the
 * unit-price × quantity arithmetic is a pure, testable function instead of an
 * inline expression buried in array assembly. See report
 * 20260622/reports/02-floating-point-math-code-review.md §5.1.
 *
 * Amounts are major-unit floats sourced from OXID Price objects, which are
 * already rounded to the shop's currency precision. forQuantity() multiplies
 * each per-unit float by the (integer) quantity exactly as the previous inline
 * code did — behaviour is preserved, no rounding is introduced here.
 */
final readonly class LineItemAmount
{
    public function __construct(
        public float $totalPrice,
        public float $netPrice,
        public float $vatValue,
    ) {
    }

    /**
     * Build the line totals by multiplying per-unit prices by the quantity.
     */
    public static function forQuantity(
        float $unitPrice,
        float $netPrice,
        float $vatValue,
        int $quantity
    ): self {
        return new self(
            $unitPrice * $quantity,
            $netPrice * $quantity,
            $vatValue * $quantity,
        );
    }
}
