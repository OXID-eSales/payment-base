<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Eshop\Core;

use OxidEsales\Eshop\Core\Price;
use OxidEsales\PaymentBase\Eshop\Core\PriceToTaxableLineMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PriceToTaxableLineMapper::class)]
class PriceToTaxableLineMapperTest extends TestCase
{
    public function testMapsPriceFields(): void
    {
        $price = $this->createMock(Price::class);
        $price->method('getPrice')->willReturn(100.0);
        $price->method('getVat')->willReturn(19.0);

        $mapper = new PriceToTaxableLineMapper();
        $line = $mapper->map($price);

        $this->assertSame(100.0, $line->amount);
        $this->assertSame(19.0, $line->vatRatePercent);
    }
}
