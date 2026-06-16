<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 *
 * Per-line VAT math is a derived work of Fresh-Advance/OXID-Per-Line-VAT
 * (MIT, © MB Arbatos Klubas). See sprint-125-strp-157-per-line-vat-math-port.md.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Eshop\Core;

use OxidEsales\Eshop\Core\Price;
use OxidEsales\PaymentBase\Math\Vat\TaxableLine;

final class PriceToTaxableLineMapper
{
    public function map(Price $price): TaxableLine
    {
        return new TaxableLine((float) $price->getPrice(), (float) $price->getVat());
    }
}
