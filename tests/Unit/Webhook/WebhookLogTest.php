<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Webhook;

use OxidEsales\PaymentBase\Webhook\WebhookLog;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidEsales\PaymentBase\Webhook\WebhookLog
 */
final class WebhookLogTest extends TestCase
{
    public function testConstructWithRequiredParameters(): void
    {
        $eventId = 'evt_test_123';
        $receivedAt = new \DateTimeImmutable('2025-10-31 12:00:00');
        $status = 'processed';

        $log = new WebhookLog($eventId, $receivedAt, $status);

        $this->assertSame($eventId, $log->getEventId());
        $this->assertSame($receivedAt, $log->getReceivedAt());
        $this->assertSame($status, $log->getStatus());
        $this->assertIsString($log->getId());
    }

    public function testIdIsUnique(): void
    {
        $log1 = new WebhookLog('evt_1', new \DateTimeImmutable(), 'processed');
        $log2 = new WebhookLog('evt_2', new \DateTimeImmutable(), 'processed');

        $this->assertNotSame($log1->getId(), $log2->getId());
    }

    public function testSetEventType(): void
    {
        $log = new WebhookLog('evt_test', new \DateTimeImmutable(), 'processed');

        $log->setEventType('payment_intent.succeeded');

        $this->assertSame('payment_intent.succeeded', $log->getEventType());
    }

    public function testSetContractId(): void
    {
        $log = new WebhookLog('evt_test', new \DateTimeImmutable(), 'processed');

        $log->setContractId('contract_123');

        $this->assertSame('contract_123', $log->getContractId());
    }

    public function testOptionalFieldsAreNullByDefault(): void
    {
        $log = new WebhookLog('evt_test', new \DateTimeImmutable(), 'processed');

        $this->assertNull($log->getEventType());
        $this->assertNull($log->getContractId());
    }

    public function testSetProcessingError(): void
    {
        $log = new WebhookLog('evt_test', new \DateTimeImmutable(), 'failed');

        $log->setError('Contract not found');

        $this->assertSame('Contract not found', $log->getError());
    }
}
