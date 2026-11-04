<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Adapter;

use OxidEsales\PaymentBase\Adapter\ShopName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(ShopName::class)]
final class ShopNameTest extends TestCase
{
    public function testReturnsTheConfiguredShopName(): void
    {
        $this->assertSame('Bäckerei Süd', ShopName::of($this->shopWithName('Bäckerei Süd')));
    }

    public function testNeverInventsABrandName(): void
    {
        // The value reaches the PSP as checkout branding; 'OXID eShop' is not the merchant's name.
        $this->assertSame('', ShopName::of(null));
        $this->assertSame('', ShopName::of(new stdClass()));
        $this->assertSame('', ShopName::of($this->shopWithName(null)));
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        $this->assertSame('Shop', ShopName::of($this->shopWithName('  Shop  ')));
    }

    private function shopWithName(mixed $name): object
    {
        $field = new stdClass();
        $field->value = $name;

        $shop = new stdClass();
        $shop->oxshops__oxname = $field;

        return $shop;
    }
}
