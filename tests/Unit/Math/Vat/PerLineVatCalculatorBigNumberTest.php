<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 *
 * Per-line VAT math is a derived work of Fresh-Advance/OXID-Per-Line-VAT
 * (MIT, © MB Arbatos Klubas). See sprint-125-strp-157-per-line-vat-math-port.md.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Math\Vat;

use OxidEsales\PaymentBase\Math\Vat\PerLineVatCalculator;
use OxidEsales\PaymentBase\Math\Vat\TaxableLine;
use OxidEsales\PaymentBase\Math\Vat\VatBreakdown;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Stress matrix for Phase A: a large, many-decimal line amount put through
 * "ugly" VAT rates (irrational divisions in both net and gross mode), plus
 * the per-line-vs-grouped divergence that is the entire reason STRP-157 exists.
 *
 * Expected values were precomputed with PHP 8.3 round(half-away-from-zero) at
 * precision=2 — the algorithm's own contract. Cases flagged DIVERGENCE /
 * OVER-COLLECTION are characterization assertions: they pin down behaviour the
 * critical review surfaced as risky, so any future change to it is visible.
 */
#[CoversClass(PerLineVatCalculator::class)]
#[CoversClass(VatBreakdown::class)]
#[CoversClass(TaxableLine::class)]
class PerLineVatCalculatorBigNumberTest extends TestCase
{
    /** Many-decimal line amount the user demanded we stress (10 fractional digits). */
    private const BIG_AMOUNT = 9456.31415927;

    /** Tight delta: values are rounded to 2dp, the delta only absorbs float noise. */
    private const DELTA = 0.0000001;

    private PerLineVatCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new PerLineVatCalculator(2);
    }

    /**
     * NET mode, single big line: vat = amount * rate / 100, rounded to 2dp.
     *
     * @return array<string, array{float, float}> rate => expectedVat
     */
    public static function netSingleLineProvider(): array
    {
        return [
            'tax 6.66%'     => [6.66, 629.79],
            'tax 0.0001%'   => [0.0001, 0.01],   // raw 0.00945631 — a sub-cent tax rounded UP to a full cent
            'tax 29.27%'    => [29.27, 2767.86],
            'tax 19%'       => [19.0, 1796.70],
            'tax 7%'        => [7.0, 661.94],
            'tax 3.3%'      => [3.3, 312.06],
            'tax 42.34567%' => [42.34567, 4004.34],
        ];
    }

    #[DataProvider('netSingleLineProvider')]
    public function testNetModeSingleBigLine(float $rate, float $expectedVat): void
    {
        $breakdown = $this->calc->calculate([new TaxableLine(self::BIG_AMOUNT, $rate)], true);

        $this->assertEqualsWithDelta($expectedVat, $breakdown->vatForRate($rate), self::DELTA);
        $this->assertEqualsWithDelta($expectedVat, $breakdown->totalVat(), self::DELTA);
    }

    /**
     * GROSS mode, single big line: vat = amount * rate / (100 + rate) — the
     * irrational-division branch (non-terminating decimals for every rate here).
     *
     * @return array<string, array{float, float}>
     */
    public static function grossSingleLineProvider(): array
    {
        return [
            'tax 6.66%'     => [6.66, 590.47],
            'tax 0.0001%'   => [0.0001, 0.01],
            'tax 29.27%'    => [29.27, 2141.15],
            'tax 19%'       => [19.0, 1509.83],
            'tax 7%'        => [7.0, 618.64],
            'tax 3.3%'      => [3.3, 302.09],
            'tax 42.34567%' => [42.34567, 2813.11],
        ];
    }

    #[DataProvider('grossSingleLineProvider')]
    public function testGrossModeSingleBigLine(float $rate, float $expectedVat): void
    {
        $breakdown = $this->calc->calculate([new TaxableLine(self::BIG_AMOUNT, $rate)], false);

        $this->assertEqualsWithDelta($expectedVat, $breakdown->vatForRate($rate), self::DELTA);
    }

    /**
     * The defining STRP-157 case: per-line rounding ≠ grouped rounding.
     * N identical big lines, NET mode. expectedPerLine is what THIS calculator
     * must produce; expectedGrouped is what OXID core would produce (sum-then-round)
     * — the gap is exactly the cent(s) a PSP would reject on amount mismatch.
     *
     * @return array<string, array{float, int, float, float}> rate, lineCount, perLine, grouped
     */
    public static function divergenceProvider(): array
    {
        return [
            // small baskets — already a 1-cent PSP reject
            'rate 29.27 x3'   => [29.27, 3, 8303.58, 8303.59],
            'rate 7 x3'       => [7.0, 3, 1985.82, 1985.83],
            'rate 7 x7'       => [7.0, 7, 4633.58, 4633.59],
            // large baskets — divergence grows with line count
            'rate 29.27 x100' => [29.27, 100, 276786.00, 276786.32],
            'rate 7 x100'     => [7.0, 100, 66194.00, 66194.20],
            'rate 19 x100'    => [19.0, 100, 179670.00, 179669.97],
            'rate 3.3 x100'   => [3.3, 100, 31206.00, 31205.84],
            // OVER-COLLECTION: a 0.0001% tax is ~0.0000945/line but per-line
            // rounding bills a full cent EACH — 100 lines collect 1.00 vs a
            // mathematically-correct ~0.0095. This is the cost of per-line 2dp.
            'rate 0.0001 x100' => [0.0001, 100, 1.00, 0.95],
        ];
    }

    #[DataProvider('divergenceProvider')]
    public function testPerLineDivergesFromGrouped(
        float $rate,
        int $lineCount,
        float $expectedPerLine,
        float $expectedGrouped
    ): void {
        $lines = array_fill(0, $lineCount, new TaxableLine(self::BIG_AMOUNT, $rate));

        $perLine = $this->calc->calculate($lines, true)->vatForRate($rate);

        $this->assertEqualsWithDelta($expectedPerLine, $perLine, self::DELTA);
        // Guard: the two strategies really do differ on this basket (else the
        // case proves nothing about per-line rounding).
        $this->assertNotEqualsWithDelta(
            $expectedGrouped,
            $perLine,
            0.001,
            "Per-line and grouped must diverge for rate $rate x$lineCount"
        );
    }

    /**
     * Mixed multi-rate basket on the big amount: each rate keyed separately,
     * totalVat sums the per-rate cents.
     */
    public function testMixedRateBasketKeysSeparatelyAndTotals(): void
    {
        $breakdown = $this->calc->calculate([
            new TaxableLine(self::BIG_AMOUNT, 19.0),
            new TaxableLine(self::BIG_AMOUNT, 7.0),
            new TaxableLine(self::BIG_AMOUNT, 3.3),
            new TaxableLine(self::BIG_AMOUNT, 42.34567),
            new TaxableLine(self::BIG_AMOUNT, 0.0001),
        ], true);

        $this->assertEqualsWithDelta(1796.70, $breakdown->vatForRate(19.0), self::DELTA);
        $this->assertEqualsWithDelta(661.94, $breakdown->vatForRate(7.0), self::DELTA);
        $this->assertEqualsWithDelta(312.06, $breakdown->vatForRate(3.3), self::DELTA);
        $this->assertEqualsWithDelta(4004.34, $breakdown->vatForRate(42.34567), self::DELTA);
        $this->assertEqualsWithDelta(0.01, $breakdown->vatForRate(0.0001), self::DELTA);
        $this->assertEqualsWithDelta(6775.05, $breakdown->totalVat(), self::DELTA);
        $this->assertCount(5, $breakdown->rates());
    }

    /**
     * Precision is a real lever on the big many-decimal amount. The 0.0001% raw
     * value is 0.009456314159…; precision controls whether it rounds to a cent,
     * a mill, or stays sub-cent.
     */
    public function testCustomPrecisionRespectedOnBigAmount(): void
    {
        $line = [new TaxableLine(self::BIG_AMOUNT, 0.0001)];

        $this->assertEqualsWithDelta(0.01, (new PerLineVatCalculator(2))->calculate($line, true)->totalVat(), self::DELTA);
        $this->assertEqualsWithDelta(0.009, (new PerLineVatCalculator(3))->calculate($line, true)->totalVat(), self::DELTA);
        $this->assertEqualsWithDelta(0.0095, (new PerLineVatCalculator(4))->calculate($line, true)->totalVat(), self::DELTA);
    }

    /**
     * KEY-STABILITY HAZARD (review finding): the algorithm keys buckets by
     * (string)$rate. For rates below 1e-4 PHP emits scientific notation
     * ('1.0E-5'), and irrational rates serialize to 14 significant figures.
     * 0.0001 is exactly on the safe side ('0.0001'); this test pins the cliff so
     * a future "support micro-rates" change can't silently produce 'E'-keys.
     */
    public function testTinyRateKeyStaysDecimalAtTheBoundary(): void
    {
        $breakdown = $this->calc->calculate([new TaxableLine(self::BIG_AMOUNT, 0.0001)], true);

        $this->assertSame(['0.0001'], array_map('strval', $breakdown->rates()));
        $this->assertStringNotContainsStringIgnoringCase('e', implode(',', array_map('strval', $breakdown->rates())));
    }
}
