<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Adapter;

use OxidEsales\PaymentBase\Adapter\ShopParameter;
use PHPUnit\Framework\TestCase;

final class ShopParameterTest extends TestCase
{
    public function testAlways_WhenShopIsOne_NamesShopOne(): void
    {
        self::assertSame('&shp=1', ShopParameter::always(1));
    }

    public function testAlways_WhenSubshop_NamesItsShop(): void
    {
        self::assertSame('&shp=3', ShopParameter::always(3));
    }

    public function testForSubshop_WhenShopIsOne_AddsNothing(): void
    {
        self::assertSame('', ShopParameter::forSubshop(1));
    }

    public function testForSubshop_WhenSubshop_NamesItsShop(): void
    {
        self::assertSame('&shp=3', ShopParameter::forSubshop(3));
    }
}
