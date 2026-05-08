<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Contract;

use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use PHPUnit\Framework\TestCase;

class BasketSnapshotTest extends TestCase
{
    public function testFromArray(): void
    {
        $data = [
            'items' => [
                ['articleId' => 'art1', 'title' => 'Product 1', 'amount' => 2, 'price' => 50.0],
            ],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
            'capturedAt' => '2025-01-01 12:00:00',
        ];

        $snapshot = BasketSnapshot::fromArray($data);

        $this->assertEquals(100.0, $snapshot->getTotalGross());
        $this->assertEquals(84.03, $snapshot->getTotalNet());
        $this->assertEquals(15.97, $snapshot->getTotalVat());
        $this->assertEquals('EUR', $snapshot->getCurrency());
        $this->assertCount(1, $snapshot->getItems());
        $this->assertCount(0, $snapshot->getDiscounts());
        $this->assertInstanceOf(\DateTimeInterface::class, $snapshot->getCapturedAt());
    }

    public function testToArray(): void
    {
        $data = [
            'items' => [
                ['articleId' => 'art1', 'title' => 'Product 1', 'amount' => 2, 'price' => 50.0],
            ],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
            'capturedAt' => '2025-01-01 12:00:00',
        ];

        $snapshot = BasketSnapshot::fromArray($data);
        $result = $snapshot->toArray();

        $this->assertEquals(100.0, $result['totalGross']);
        $this->assertEquals(84.03, $result['totalNet']);
        $this->assertEquals(15.97, $result['totalVat']);
        $this->assertEquals('EUR', $result['currency']);
        $this->assertIsArray($result['items']);
        $this->assertIsArray($result['discounts']);
        $this->assertIsString($result['capturedAt']);
    }

    public function testImmutability(): void
    {
        $data = [
            'items' => [['articleId' => 'art1']],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
            'capturedAt' => '2025-01-01 12:00:00',
        ];

        $snapshot = BasketSnapshot::fromArray($data);
        $items = $snapshot->getItems();
        $items[] = ['articleId' => 'art2'];

        $this->assertCount(1, $snapshot->getItems());
    }

    public function testCapturedAtIsSet(): void
    {
        $data = [
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
            'capturedAt' => '2025-01-01 12:00:00',
        ];

        $snapshot = BasketSnapshot::fromArray($data);

        $this->assertNotNull($snapshot->getCapturedAt());
        $this->assertEquals('2025-01-01 12:00:00', $snapshot->getCapturedAt()->format('Y-m-d H:i:s'));
    }

    // ==========================================
    // Sprint 47: Fix 5 - Amount validation (STRP-99)
    // ==========================================

    /**
     * @dataProvider invalidAmountProvider
     */
    public function testExtractFloatRejectsInvalidAmounts(float $amount, string $field): void
    {
        $data = [
            'items' => [],
            'discounts' => [],
            'totalGross' => $field === 'totalGross' ? $amount : 100.0,
            'totalNet' => $field === 'totalNet' ? $amount : 84.03,
            'totalVat' => $field === 'totalVat' ? $amount : 15.97,
            'currency' => 'EUR',
        ];

        $this->expectException(\InvalidArgumentException::class);

        BasketSnapshot::fromArray($data);
    }

    /**
     * @return array<string, array{float, string}>
     */
    public static function invalidAmountProvider(): array
    {
        return [
            'negative totalGross' => [-1.0, 'totalGross'],
            'negative totalNet' => [-0.01, 'totalNet'],
            'INF totalGross' => [INF, 'totalGross'],
            '-INF totalNet' => [-INF, 'totalNet'],
            'NAN totalVat' => [NAN, 'totalVat'],
        ];
    }

    /**
     * @dataProvider validAmountProvider
     */
    public function testExtractFloatAcceptsValidAmounts(float $gross, float $net, float $vat): void
    {
        $data = [
            'items' => [],
            'discounts' => [],
            'totalGross' => $gross,
            'totalNet' => $net,
            'totalVat' => $vat,
            'currency' => 'EUR',
        ];

        $snapshot = BasketSnapshot::fromArray($data);

        $this->assertEquals($gross, $snapshot->getTotalGross());
        $this->assertEquals($net, $snapshot->getTotalNet());
        $this->assertEquals($vat, $snapshot->getTotalVat());
    }

    /**
     * @return array<string, array{float, float, float}>
     */
    public static function validAmountProvider(): array
    {
        return [
            'zero' => [0.0, 0.0, 0.0],
            'small' => [0.01, 0.01, 0.0],
            'normal' => [99.99, 84.03, 15.96],
            'large' => [99999.99, 84033.61, 15966.38],
        ];
    }

    // ==========================================
    // Sprint 47: Fix 6 - Currency validation (STRP-99)
    // ==========================================

    /**
     * @dataProvider invalidCurrencyProvider
     */
    public function testExtractCurrencyRejectsInvalidCodes(string $currency): void
    {
        $data = [
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => $currency,
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ISO 4217');

        BasketSnapshot::fromArray($data);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidCurrencyProvider(): array
    {
        return [
            'lowercase' => ['eur'],
            'four letters' => ['XXXX'],
            'two letters' => ['EU'],
            'single letter' => ['E'],
            'empty' => [''],
            'numbers' => ['123'],
            'mixed case' => ['Eur'],
            'script injection' => ['<script>'],
            'with spaces' => ['EU '],
        ];
    }

    /**
     * @dataProvider validCurrencyProvider
     */
    public function testExtractCurrencyAcceptsValidCodes(string $currency): void
    {
        $data = [
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => $currency,
        ];

        $snapshot = BasketSnapshot::fromArray($data);

        $this->assertEquals($currency, $snapshot->getCurrency());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validCurrencyProvider(): array
    {
        return [
            'EUR' => ['EUR'],
            'USD' => ['USD'],
            'GBP' => ['GBP'],
            'CHF' => ['CHF'],
            'JPY' => ['JPY'],
        ];
    }
}
