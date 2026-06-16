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

final class VatBreakdown
{
    /**
     * @param array<string|int,float> $vatByRate keys are string VAT rate values (e.g. '19', '7.5')
     */
    public function __construct(private readonly array $vatByRate)
    {
    }

    public function vatForRate(float $rate): float
    {
        return $this->vatByRate[(string) $rate] ?? 0.0;
    }

    public function totalVat(): float
    {
        return array_sum($this->vatByRate);
    }

    /** @return list<float> */
    public function rates(): array
    {
        return array_values(array_map('floatval', array_keys($this->vatByRate)));
    }

    /** @return array<string|int,float> */
    public function toArray(): array
    {
        return $this->vatByRate;
    }
}
