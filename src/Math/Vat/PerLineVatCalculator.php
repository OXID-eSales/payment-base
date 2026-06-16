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

final class PerLineVatCalculator implements PerLineVatCalculatorInterface
{
    public function __construct(private readonly int $precision = 2)
    {
    }

    public function calculate(array $lines, bool $netMode): VatBreakdown
    {
        if ($lines === []) {
            return new VatBreakdown([]);
        }

        /** @var array<string,float> $vatByRate */
        $vatByRate = [];
        foreach ($lines as $line) {
            $vat = $netMode
                ? $line->amount * $line->vatRatePercent / 100
                : $line->amount * $line->vatRatePercent / (100 + $line->vatRatePercent);
            $vat = round($vat, $this->precision);
            $key = (string) $line->vatRatePercent;
            $vatByRate[$key] = ($vatByRate[$key] ?? 0.0) + $vat;
        }

        return new VatBreakdown($vatByRate);
    }
}
