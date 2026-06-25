<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Webhook;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Webhook\WebhookLog;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\Group;

/**
 * TDD Tests for WebhookLog provider and payload fields
 *
 * Sprint 2 Phase 1: Webhook table consolidation requires adding
 * provider and payload fields to WebhookLog entity.
 */
#[Group('sprint-2')]
#[Group('webhook-consolidation')]
class WebhookLogProviderFieldsTest extends TestCase
{
    /**
     * RED: WebhookLog should support provider field
     */
    #[Test]
    public function webhookLogSupportsProviderField(): void
    {
        $log = new WebhookLog(
            'evt_test_123',
            new DateTimeImmutable(),
            'received'
        );

        $log->setProvider('stripe');

        $this->assertEquals('stripe', $log->getProvider());
    }

    /**
     * RED: WebhookLog should support payload field
     */
    #[Test]
    public function webhookLogSupportsPayloadField(): void
    {
        $log = new WebhookLog(
            'evt_test_456',
            new DateTimeImmutable(),
            'received'
        );

        $payload = ['id' => 'pi_123', 'status' => 'succeeded'];
        $log->setPayload($payload);

        $this->assertEquals($payload, $log->getPayload());
    }

    /**
     * RED: Provider should default to null
     */
    #[Test]
    public function providerDefaultsToNull(): void
    {
        $log = new WebhookLog(
            'evt_test_789',
            new DateTimeImmutable(),
            'received'
        );

        $this->assertNull($log->getProvider());
    }

    /**
     * RED: Payload should default to null
     */
    #[Test]
    public function payloadDefaultsToNull(): void
    {
        $log = new WebhookLog(
            'evt_test_abc',
            new DateTimeImmutable(),
            'received'
        );

        $this->assertNull($log->getPayload());
    }

    /**
     * RED: WebhookLog should support processedAt field
     */
    #[Test]
    public function webhookLogSupportsProcessedAtField(): void
    {
        $log = new WebhookLog(
            'evt_test_def',
            new DateTimeImmutable(),
            'received'
        );

        $processedAt = new DateTimeImmutable('2025-12-02 15:30:00');
        $log->setProcessedAt($processedAt);

        $this->assertEquals($processedAt, $log->getProcessedAt());
    }

    /**
     * RED: ProcessedAt should default to null
     */
    #[Test]
    public function processedAtDefaultsToNull(): void
    {
        $log = new WebhookLog(
            'evt_test_ghi',
            new DateTimeImmutable(),
            'received'
        );

        $this->assertNull($log->getProcessedAt());
    }
}
