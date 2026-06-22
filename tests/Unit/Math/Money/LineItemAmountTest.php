<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Math\Money;

use OxidEsales\PaymentBase\Math\Money\LineItemAmount;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(LineItemAmount::class)]
class LineItemAmountTest extends TestCase
{
    public function testConstructorExposesComponents(): void
    {
        $amount = new LineItemAmount(59.97, 50.39, 9.58);

        $this->assertSame(59.97, $amount->totalPrice);
        $this->assertSame(50.39, $amount->netPrice);
        $this->assertSame(9.58, $amount->vatValue);
    }

    public function testIsReadonly(): void
    {
        $ref = new ReflectionClass(LineItemAmount::class);

        $this->assertTrue($ref->isReadOnly());
    }

    public function testForQuantityMultipliesEachComponent(): void
    {
        $amount = LineItemAmount::forQuantity(19.99, 16.80, 3.19, 3);

        $this->assertEqualsWithDelta(59.97, $amount->totalPrice, 1e-9);
        $this->assertEqualsWithDelta(50.40, $amount->netPrice, 1e-9);
        $this->assertEqualsWithDelta(9.57, $amount->vatValue, 1e-9);
    }

    public function testQuantityOfOneIsIdentity(): void
    {
        $amount = LineItemAmount::forQuantity(12.34, 10.37, 1.97, 1);

        $this->assertSame(12.34, $amount->totalPrice);
        $this->assertSame(10.37, $amount->netPrice);
        $this->assertSame(1.97, $amount->vatValue);
    }

    public function testQuantityOfZeroYieldsZeroTotals(): void
    {
        $amount = LineItemAmount::forQuantity(19.99, 16.80, 3.19, 0);

        $this->assertSame(0.0, $amount->totalPrice);
        $this->assertSame(0.0, $amount->netPrice);
        $this->assertSame(0.0, $amount->vatValue);
    }

    public function testLargeQuantityScalesLinearly(): void
    {
        $amount = LineItemAmount::forQuantity(1.50, 1.26, 0.24, 1000);

        $this->assertEqualsWithDelta(1500.0, $amount->totalPrice, 1e-9);
        $this->assertEqualsWithDelta(1260.0, $amount->netPrice, 1e-9);
        $this->assertEqualsWithDelta(240.0, $amount->vatValue, 1e-9);
    }

    public function testZeroPricedLineStaysZero(): void
    {
        $amount = LineItemAmount::forQuantity(0.0, 0.0, 0.0, 5);

        $this->assertSame(0.0, $amount->totalPrice);
        $this->assertSame(0.0, $amount->netPrice);
        $this->assertSame(0.0, $amount->vatValue);
    }

    /**
     * @return array<string, array{float, float, float, int, float, float, float}>
     */
    public static function multiplicationProvider(): array
    {
        return [
            //                unit,  net,    vat,   qty, totalP,  netP,    vatV
            'single unit'  => [9.99,  8.39,  1.60,  1,   9.99,    8.39,    1.60],
            'two units'    => [9.99,  8.39,  1.60,  2,   19.98,   16.78,   3.20],
            'reduced rate' => [4.99,  4.66,  0.33,  7,   34.93,   32.62,   2.31],
            'fractional'   => [0.01,  0.01,  0.00,  99,  0.99,    0.99,    0.00],
        ];
    }

    #[DataProvider('multiplicationProvider')]
    public function testForQuantityMatrix(
        float $unit,
        float $net,
        float $vat,
        int $qty,
        float $expectedTotal,
        float $expectedNet,
        float $expectedVat
    ): void {
        $amount = LineItemAmount::forQuantity($unit, $net, $vat, $qty);

        $this->assertEqualsWithDelta($expectedTotal, $amount->totalPrice, 1e-9);
        $this->assertEqualsWithDelta($expectedNet, $amount->netPrice, 1e-9);
        $this->assertEqualsWithDelta($expectedVat, $amount->vatValue, 1e-9);
    }
}
