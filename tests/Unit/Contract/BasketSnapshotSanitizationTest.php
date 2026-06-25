<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Contract;

use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Sprint 69b: H6 — Basket snapshot PII whitelist.
 *
 * Tests that BasketSnapshot strips non-whitelisted fields from items.
 */
#[CoversClass(\OxidEsales\PaymentBase\Contract\BasketSnapshot::class)]
#[Group('sprint-69b')]
#[Group('security')]
final class BasketSnapshotSanitizationTest extends TestCase
{
    #[Test]
    public function snapshotKeepsProductId(): void
    {
        $snapshot = $this->createSnapshotWithItem(['artnum' => 'ABC123']);

        $this->assertSame('ABC123', $snapshot->getItems()[0]['artnum']);
    }

    #[Test]
    public function snapshotKeepsTitle(): void
    {
        $snapshot = $this->createSnapshotWithItem(['title' => 'Widget']);

        $this->assertSame('Widget', $snapshot->getItems()[0]['title']);
    }

    #[Test]
    public function snapshotKeepsQuantity(): void
    {
        $snapshot = $this->createSnapshotWithItem(['quantity' => 2]);

        $this->assertSame(2, $snapshot->getItems()[0]['quantity']);
    }

    #[Test]
    public function snapshotKeepsPrice(): void
    {
        $snapshot = $this->createSnapshotWithItem(['price' => 19.99]);

        $this->assertSame(19.99, $snapshot->getItems()[0]['price']);
    }

    #[Test]
    public function snapshotKeepsVat(): void
    {
        $snapshot = $this->createSnapshotWithItem(['vat' => 19.0]);

        $this->assertSame(19.0, $snapshot->getItems()[0]['vat']);
    }

    #[Test]
    public function snapshotStripsUnknownItemFields(): void
    {
        $snapshot = $this->createSnapshotWithItem([
            'artnum' => 'X',
            'unknownField' => 'secret_data',
            'internalId' => 999,
        ]);

        $item = $snapshot->getItems()[0];
        $this->assertArrayHasKey('artnum', $item);
        $this->assertArrayNotHasKey('unknownField', $item);
        $this->assertArrayNotHasKey('internalId', $item);
    }

    #[Test]
    public function snapshotStripsGiftMessage(): void
    {
        $snapshot = $this->createSnapshotWithItem([
            'artnum' => 'X',
            'giftMessage' => 'Happy birthday dear friend!',
        ]);

        $this->assertArrayNotHasKey('giftMessage', $snapshot->getItems()[0]);
    }

    #[Test]
    public function snapshotStripsPersonalization(): void
    {
        $snapshot = $this->createSnapshotWithItem([
            'artnum' => 'X',
            'personalization' => 'Custom engraving: John Doe',
        ]);

        $this->assertArrayNotHasKey('personalization', $snapshot->getItems()[0]);
    }

    #[Test]
    public function sanitizeItemsIsDeterministic(): void
    {
        $data = $this->buildSnapshotData([['artnum' => 'A', 'extra' => 'val']]);

        $snapshot1 = BasketSnapshot::fromArray($data);
        $snapshot2 = BasketSnapshot::fromArray($data);

        $this->assertSame($snapshot1->getItems(), $snapshot2->getItems());
    }

    /**
     * @param array<string, mixed> $itemFields
     */
    private function createSnapshotWithItem(array $itemFields): BasketSnapshot
    {
        return BasketSnapshot::fromArray($this->buildSnapshotData([$itemFields]));
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function buildSnapshotData(array $items): array
    {
        return [
            'items' => $items,
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
            'capturedAt' => date('Y-m-d H:i:s'),
        ];
    }
}
