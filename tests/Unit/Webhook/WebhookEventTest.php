<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Webhook;

use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(\OxidEsales\PaymentBase\Webhook\WebhookEvent::class)]
#[Group('sprint-13')]
#[Group('webhook')]
final class WebhookEventTest extends TestCase
{
    #[Test]
    public function canCreateFromData(): void
    {
        $event = new WebhookEvent(
            id: 'evt_123',
            type: 'payment_intent.succeeded',
            data: ['object' => ['id' => 'pi_456']],
            created: 1733400000
        );

        $this->assertInstanceOf(WebhookEvent::class, $event);
    }

    #[Test]
    public function getIdReturnsEventId(): void
    {
        $event = new WebhookEvent('evt_abc123', 'payment_intent.succeeded', [], 0);

        $this->assertSame('evt_abc123', $event->id);
    }

    #[Test]
    public function getTypeReturnsEventType(): void
    {
        $event = new WebhookEvent('evt_123', 'charge.refunded', [], 0);

        $this->assertSame('charge.refunded', $event->type);
    }

    #[Test]
    public function getDataReturnsPayload(): void
    {
        $data = [
            'object' => [
                'id' => 'pi_test_456',
                'status' => 'succeeded',
                'amount' => 5000,
            ],
        ];
        $event = new WebhookEvent('evt_123', 'payment_intent.succeeded', $data, 0);

        $this->assertSame($data, $event->data);
    }

    #[Test]
    public function getCreatedReturnsTimestamp(): void
    {
        $created = 1733400000;
        $event = new WebhookEvent('evt_123', 'payment_intent.succeeded', [], $created);

        $this->assertSame($created, $event->created);
    }

    #[Test]
    public function propertiesAreReadOnly(): void
    {
        $event = new WebhookEvent('evt_123', 'payment_intent.succeeded', [], 0);

        $reflection = new \ReflectionClass($event);

        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue(
                $property->isReadOnly(),
                "Property {$property->getName()} should be readonly"
            );
        }
    }

    #[Test]
    public function getObjectIdExtractsIdFromData(): void
    {
        $event = new WebhookEvent(
            'evt_123',
            'payment_intent.succeeded',
            ['object' => ['id' => 'pi_extracted_id']],
            0
        );

        $this->assertSame('pi_extracted_id', $event->getObjectId());
    }

    #[Test]
    public function getObjectIdReturnsNullWhenMissing(): void
    {
        $event = new WebhookEvent('evt_123', 'payment_intent.succeeded', [], 0);

        $this->assertNull($event->getObjectId());
    }

    #[Test]
    public function getObjectReturnsDataObject(): void
    {
        $object = ['id' => 'pi_123', 'status' => 'succeeded'];
        $event = new WebhookEvent('evt_123', 'payment_intent.succeeded', ['object' => $object], 0);

        $this->assertSame($object, $event->getObject());
    }

    #[Test]
    public function getObjectReturnsEmptyArrayWhenMissing(): void
    {
        $event = new WebhookEvent('evt_123', 'payment_intent.succeeded', [], 0);

        $this->assertSame([], $event->getObject());
    }

    #[Test]
    public function isTypeReturnsTrueForMatchingType(): void
    {
        $event = new WebhookEvent('evt_123', 'payment_intent.succeeded', [], 0);

        $this->assertTrue($event->isType('payment_intent.succeeded'));
    }

    #[Test]
    public function isTypeReturnsFalseForNonMatchingType(): void
    {
        $event = new WebhookEvent('evt_123', 'payment_intent.succeeded', [], 0);

        $this->assertFalse($event->isType('charge.refunded'));
    }
}
