<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Contract;

use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
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
}
