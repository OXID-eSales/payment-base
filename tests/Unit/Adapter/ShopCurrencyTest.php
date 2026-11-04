<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Adapter;

use OxidEsales\PaymentBase\Adapter\ShopCurrency;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

#[CoversClass(ShopCurrency::class)]
final class ShopCurrencyTest extends TestCase
{
    public function testReturnsTheConfiguredCurrencyName(): void
    {
        $currency = new stdClass();
        $currency->name = 'CHF';

        $this->assertSame('CHF', ShopCurrency::nameOf($currency, 'test'));
    }

    public function testThrowsWhenCurrencyObjectIsMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to assume a currency');

        ShopCurrency::nameOf(null, 'test');
    }

    /** Config::getActShopCurrencyObject() returns false when no currency is configured. */
    public function testThrowsWhenCoreReturnsFalse(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to assume a currency');

        ShopCurrency::nameOf(false, 'test');
    }

    public function testThrowsWhenNameIsMissing(): void
    {
        $this->expectException(RuntimeException::class);

        ShopCurrency::nameOf(new stdClass(), 'test');
    }

    public function testThrowsWhenNameIsEmpty(): void
    {
        $currency = new stdClass();
        $currency->name = '';

        $this->expectException(RuntimeException::class);

        ShopCurrency::nameOf($currency, 'test');
    }

    public function testNeverSubstitutesEur(): void
    {
        try {
            ShopCurrency::nameOf(false, 'test');
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('EUR', $exception->getMessage());
        }
    }

    public function testDisplayOnlyResolutionReturnsEmptyInsteadOfGuessing(): void
    {
        $this->assertSame('', ShopCurrency::nameOrEmpty(null));
        $this->assertSame('', ShopCurrency::nameOrEmpty(false));
        $this->assertSame('', ShopCurrency::nameOrEmpty(new stdClass()));
    }
}
