<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Math\Money;

use OxidEsales\PaymentBase\Math\Money\MinorUnitConverter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MinorUnitConverter::class)]
class MinorUnitConverterTest extends TestCase
{
    /**
     * @return array<string, array{string, int}>
     */
    public static function decimalsProvider(): array
    {
        return [
            'EUR is 2-decimal'      => ['EUR', 2],
            'USD is 2-decimal'      => ['USD', 2],
            'lowercase eur'         => ['eur', 2],
            'JPY is 0-decimal'      => ['JPY', 0],
            'KRW is 0-decimal'      => ['KRW', 0],
            'BHD is 3-decimal'      => ['BHD', 3],
            'KWD is 3-decimal'      => ['KWD', 3],
            'unknown defaults to 2' => ['ZZZ', 2],
            'empty defaults to 2'   => ['', 2],
        ];
    }

    #[DataProvider('decimalsProvider')]
    public function testDecimalsFor(string $currency, int $expected): void
    {
        $this->assertSame($expected, MinorUnitConverter::decimalsFor($currency));
    }

    /**
     * @return array<string, array{float, string, int}>
     */
    public static function toMinorProvider(): array
    {
        return [
            'EUR cents'             => [19.99, 'EUR', 1999],
            'EUR drift case'        => [0.29, 'EUR', 29],
            'EUR rounds half up'    => [10.005, 'EUR', 1001],
            'JPY stays as-is'       => [1000.0, 'JPY', 1000],
            'BHD 3-decimal'         => [1.234, 'BHD', 1234],
            'empty currency 2dp'    => [5.50, '', 550],
            'zero'                  => [0.0, 'EUR', 0],
        ];
    }

    #[DataProvider('toMinorProvider')]
    public function testToMinorUnits(float $major, string $currency, int $expected): void
    {
        $this->assertSame($expected, MinorUnitConverter::toMinorUnits($major, $currency));
    }

    /**
     * Defeats the classic IEEE-754 truncation bug: 19.99 * 100 = 1998.9999…
     */
    public function testToMinorUnitsAvoidsTruncationDrift(): void
    {
        $this->assertSame(1999, MinorUnitConverter::toMinorUnits(19.99, 'EUR'));
    }

    /**
     * @return array<string, array{int, string, float}>
     */
    public static function toMajorProvider(): array
    {
        return [
            'EUR cents'         => [1999, 'EUR', 19.99],
            'JPY stays as-is'   => [1000, 'JPY', 1000.0],
            'BHD 3-decimal'     => [1234, 'BHD', 1.234],
            'zero'              => [0, 'EUR', 0.0],
        ];
    }

    #[DataProvider('toMajorProvider')]
    public function testToMajorUnits(int $minor, string $currency, float $expected): void
    {
        $this->assertEqualsWithDelta($expected, MinorUnitConverter::toMajorUnits($minor, $currency), 1e-9);
    }

    public function testRoundTripPreservesAmount(): void
    {
        $cents = MinorUnitConverter::toMinorUnits(123.45, 'EUR');

        $this->assertSame(12345, $cents);
        $this->assertEqualsWithDelta(123.45, MinorUnitConverter::toMajorUnits($cents, 'EUR'), 1e-9);
    }
}
