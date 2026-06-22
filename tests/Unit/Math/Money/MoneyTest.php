<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Math\Money;

use OxidEsales\PaymentBase\Math\Money\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Money::class)]
class MoneyTest extends TestCase
{
    public function testEpsilonIsHalfCent(): void
    {
        $this->assertSame(0.005, Money::HALF_CENT_EPSILON);
    }

    /**
     * @return array<string, array{float, float, bool}>
     */
    public static function equalsProvider(): array
    {
        return [
            'exact'                 => [10.00, 10.00, true],
            'within tolerance'      => [10.00, 10.004, true],
            'float drift'           => [0.1 + 0.2, 0.3, true],
            'one cent apart'        => [10.00, 10.01, false],
            'half cent boundary'    => [10.00, 10.005, false],
            'negative within'       => [-5.00, -5.004, true],
        ];
    }

    #[DataProvider('equalsProvider')]
    public function testEquals(float $a, float $b, bool $expected): void
    {
        $this->assertSame($expected, Money::equals($a, $b));
    }

    public function testEqualsIsSymmetric(): void
    {
        $this->assertSame(Money::equals(10.00, 10.004), Money::equals(10.004, 10.00));
    }

    /**
     * @return array<string, array{float, float, bool}>
     */
    public static function greaterThanProvider(): array
    {
        return [
            'clearly greater'       => [10.02, 10.00, true],
            'equal'                 => [10.00, 10.00, false],
            'within tolerance'      => [10.004, 10.00, false],
            'just beyond tolerance' => [10.006, 10.00, true],
            'less'                  => [9.99, 10.00, false],
        ];
    }

    #[DataProvider('greaterThanProvider')]
    public function testGreaterThan(float $a, float $b, bool $expected): void
    {
        $this->assertSame($expected, Money::greaterThan($a, $b));
    }

    /**
     * @return array<string, array{float, float, bool}>
     */
    public static function atLeastProvider(): array
    {
        return [
            'greater'               => [10.01, 10.00, true],
            'equal'                 => [10.00, 10.00, true],
            'sub-tolerance short'   => [9.996, 10.00, true],
            'one cent short'        => [9.99, 10.00, false],
        ];
    }

    #[DataProvider('atLeastProvider')]
    public function testAtLeast(float $a, float $b, bool $expected): void
    {
        $this->assertSame($expected, Money::atLeast($a, $b));
    }

    /**
     * @return array<string, array{float, float, bool}>
     */
    public static function atMostProvider(): array
    {
        return [
            'less'                  => [9.99, 10.00, true],
            'equal'                 => [10.00, 10.00, true],
            'sub-tolerance over'    => [10.004, 10.00, true],
            'one cent over'         => [10.01, 10.00, false],
        ];
    }

    #[DataProvider('atMostProvider')]
    public function testAtMost(float $a, float $b, bool $expected): void
    {
        $this->assertSame($expected, Money::atMost($a, $b));
    }
}
