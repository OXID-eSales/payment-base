<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Math\Vat;

use OxidEsales\PaymentBase\Math\Vat\PerLineVatCalculator;
use OxidEsales\PaymentBase\Math\Vat\TaxableLine;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PerLineVatCalculator::class)]
class PerLineVatCalculatorTest extends TestCase
{
    private const DELTA = 0.0000001;

    public function testNetModeSingleLine(): void
    {
        $calc = new PerLineVatCalculator(2);

        $breakdown = $calc->calculate([new TaxableLine(100.0, 19.0)], true);

        $this->assertEqualsWithDelta(19.0, $breakdown->vatForRate(19.0), self::DELTA);
    }

    public function testGrossModeSingleLine(): void
    {
        $calc = new PerLineVatCalculator(2);

        // 119.0 * 19 / (100 + 19) = 119.0 * 19 / 119 = 19.0
        $breakdown = $calc->calculate([new TaxableLine(119.0, 19.0)], false);

        $this->assertEqualsWithDelta(19.0, $breakdown->vatForRate(19.0), self::DELTA);
    }

    public function testAccumulatesMultipleLinesSameRate(): void
    {
        $calc = new PerLineVatCalculator(2);
        // per line: round(10*19/100,2)=1.9, round(20*19/100,2)=3.8 → total 5.7
        $lines = [new TaxableLine(10.0, 19.0), new TaxableLine(20.0, 19.0)];

        $breakdown = $calc->calculate($lines, true);

        $this->assertEqualsWithDelta(5.7, $breakdown->vatForRate(19.0), self::DELTA);
    }

    public function testRoundsPerLineNotOnSum(): void
    {
        $calc = new PerLineVatCalculator(2);
        // Two lines of 0.555 @ 19%:
        // per-line: round(0.555*19/100,2) = round(0.10545,2) = 0.11 each → 0.22
        // grouped:  round((0.555+0.555)*19/100,2) = round(0.2109,2) = 0.21
        // Per-line rounding produces 0.22, not 0.21.
        $lines = [new TaxableLine(0.555, 19.0), new TaxableLine(0.555, 19.0)];

        $breakdown = $calc->calculate($lines, true);

        $this->assertEqualsWithDelta(0.22, $breakdown->vatForRate(19.0), self::DELTA);
    }

    public function testMultipleRatesKeyedSeparately(): void
    {
        $calc = new PerLineVatCalculator(2);
        $lines = [new TaxableLine(100.0, 19.0), new TaxableLine(100.0, 7.0)];

        $breakdown = $calc->calculate($lines, true);

        $this->assertEqualsWithDelta(19.0, $breakdown->vatForRate(19.0), self::DELTA);
        $this->assertEqualsWithDelta(7.0, $breakdown->vatForRate(7.0), self::DELTA);
    }

    public function testEmptyListYieldsEmptyBreakdown(): void
    {
        $calc = new PerLineVatCalculator(2);

        $breakdown = $calc->calculate([], true);

        $this->assertSame([], $breakdown->rates());
        $this->assertEqualsWithDelta(0.0, $breakdown->totalVat(), self::DELTA);
    }

    public function testZeroRateProducesZeroVat(): void
    {
        $calc = new PerLineVatCalculator(2);

        $breakdown = $calc->calculate([new TaxableLine(50.0, 0.0)], true);

        $this->assertEqualsWithDelta(0.0, $breakdown->vatForRate(0.0), self::DELTA);
    }

    public function testCustomPrecisionRespected(): void
    {
        $calc = new PerLineVatCalculator(3);
        // 100.1234 * 7 / 100 = 7.008638 → round(7.008638, 3) = 7.009
        $breakdown = $calc->calculate([new TaxableLine(100.1234, 7.0)], true);

        $this->assertEqualsWithDelta(7.009, $breakdown->vatForRate(7.0), self::DELTA);
    }
}
