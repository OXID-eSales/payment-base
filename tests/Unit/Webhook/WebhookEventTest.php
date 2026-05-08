<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Webhook;

use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidEsales\PaymentBase\Webhook\WebhookEvent
 * @group sprint-13
 * @group webhook
 */
final class WebhookEventTest extends TestCase
{
    /**
     * @test
     */
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

    /**
     * @test
     */
    public function getIdReturnsEventId(): void
    {
        $event = new WebhookEvent('evt_abc123', 'payment_intent.succeeded', [], 0);

        $this->assertSame('evt_abc123', $event->id);
    }

    /**
     * @test
     */
    public function getTypeReturnsEventType(): void
    {
        $event = new WebhookEvent('evt_123', 'charge.refunded', [], 0);

        $this->assertSame('charge.refunded', $event->type);
    }

    /**
     * @test
     */
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

    /**
     * @test
     */
    public function getCreatedReturnsTimestamp(): void
    {
        $created = 1733400000;
        $event = new WebhookEvent('evt_123', 'payment_intent.succeeded', [], $created);

        $this->assertSame($created, $event->created);
    }

    /**
     * @test
     */
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

    /**
     * @test
     */
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

    /**
     * @test
     */
    public function getObjectIdReturnsNullWhenMissing(): void
    {
        $event = new WebhookEvent('evt_123', 'payment_intent.succeeded', [], 0);

        $this->assertNull($event->getObjectId());
    }

    /**
     * @test
     */
    public function getObjectReturnsDataObject(): void
    {
        $object = ['id' => 'pi_123', 'status' => 'succeeded'];
        $event = new WebhookEvent('evt_123', 'payment_intent.succeeded', ['object' => $object], 0);

        $this->assertSame($object, $event->getObject());
    }

    /**
     * @test
     */
    public function getObjectReturnsEmptyArrayWhenMissing(): void
    {
        $event = new WebhookEvent('evt_123', 'payment_intent.succeeded', [], 0);

        $this->assertSame([], $event->getObject());
    }

    /**
     * @test
     */
    public function isTypeReturnsTrueForMatchingType(): void
    {
        $event = new WebhookEvent('evt_123', 'payment_intent.succeeded', [], 0);

        $this->assertTrue($event->isType('payment_intent.succeeded'));
    }

    /**
     * @test
     */
    public function isTypeReturnsFalseForNonMatchingType(): void
    {
        $event = new WebhookEvent('evt_123', 'payment_intent.succeeded', [], 0);

        $this->assertFalse($event->isType('charge.refunded'));
    }
}
