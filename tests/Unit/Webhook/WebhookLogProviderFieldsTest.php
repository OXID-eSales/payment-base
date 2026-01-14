<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Webhook;

use DateTimeImmutable;
use OxidEsales\PaymentComponent\Webhook\WebhookLog;
use PHPUnit\Framework\TestCase;

/**
 * TDD Tests for WebhookLog provider and payload fields
 *
 * Sprint 2 Phase 1: Webhook table consolidation requires adding
 * provider and payload fields to WebhookLog entity.
 *
 * @group sprint-2
 * @group webhook-consolidation
 */
class WebhookLogProviderFieldsTest extends TestCase
{
    /**
     * @test
     * RED: WebhookLog should support provider field
     */
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
     * @test
     * RED: WebhookLog should support payload field
     */
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
     * @test
     * RED: Provider should default to null
     */
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
     * @test
     * RED: Payload should default to null
     */
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
     * @test
     * RED: WebhookLog should support processedAt field
     */
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
     * @test
     * RED: ProcessedAt should default to null
     */
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
