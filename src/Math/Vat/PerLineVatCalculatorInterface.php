<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 *
 * Per-line VAT math is a derived work of Fresh-Advance/OXID-Per-Line-VAT
 * (MIT, © MB Arbatos Klubas). See sprint-125-strp-157-per-line-vat-math-port.md.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Math\Vat;

interface PerLineVatCalculatorInterface
{
    /** @param list<TaxableLine> $lines */
    public function calculate(array $lines, bool $netMode): VatBreakdown;
}
