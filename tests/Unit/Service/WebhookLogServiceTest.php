<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Service;

use DateTimeImmutable;
use OxidEsales\PaymentComponent\Repository\WebhookLogRepository;
use OxidEsales\PaymentComponent\Service\WebhookLogService;
use OxidEsales\PaymentComponent\Webhook\WebhookLog;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OxidEsales\PaymentComponent\Service\WebhookLogService
 */
final class WebhookLogServiceTest extends TestCase
{
    private WebhookLogRepository $repository;
    private LoggerInterface $logger;
    private WebhookLogService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new WebhookLogRepository();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new WebhookLogService($this->repository, $this->logger);
    }

    public function testLogEventReceivedCreatesWebhookLog(): void
    {
        $eventId = 'evt_test_123';
        $eventType = 'payment_intent.succeeded';
        $payload = ['id' => 'pi_test', 'amount' => 1000];

        $result = $this->service->logEventReceived($eventId, $eventType, $payload);

        $this->assertInstanceOf(WebhookLog::class, $result);
        $this->assertSame($eventId, $result->getEventId());
        $this->assertSame($eventType, $result->getEventType());
        $this->assertSame('stripe', $result->getProvider());
        $this->assertSame(WebhookLogService::STATUS_RECEIVED, $result->getStatus());
        $this->assertSame($payload, $result->getPayload());
    }

    public function testLogEventReceivedPersistsToRepository(): void
    {
        $eventId = 'evt_test_persist';
        $eventType = 'charge.refunded';
        $payload = ['id' => 'ch_test'];

        $this->service->logEventReceived($eventId, $eventType, $payload);

        $found = $this->repository->findByEventId($eventId);
        $this->assertNotNull($found);
        $this->assertSame($eventId, $found->getEventId());
    }

    public function testLogEventReceivedWithCustomProvider(): void
    {
        $eventId = 'evt_custom_provider';
        $eventType = 'payment.completed';
        $payload = [];

        $result = $this->service->logEventReceived($eventId, $eventType, $payload, 'unzer');

        $this->assertSame('unzer', $result->getProvider());
    }

    public function testMarkEventProcessedUpdatesStatus(): void
    {
        // First create an event
        $eventId = 'evt_to_process';
        $this->service->logEventReceived($eventId, 'payment_intent.succeeded', []);

        // Mark as processed
        $this->service->markEventProcessed($eventId);

        // Verify via repository
        $statusUpdate = $this->repository->getStatusUpdate($eventId);
        $this->assertNotNull($statusUpdate);
        $this->assertSame(WebhookLogService::STATUS_PROCESSED, $statusUpdate['status']);
        $this->assertNull($statusUpdate['contractId']);
    }

    public function testMarkEventProcessedWithContractId(): void
    {
        $eventId = 'evt_with_contract';
        $contractId = 'contract_abc123';
        $this->service->logEventReceived($eventId, 'payment_intent.succeeded', []);

        $this->service->markEventProcessed($eventId, $contractId);

        $statusUpdate = $this->repository->getStatusUpdate($eventId);
        $this->assertNotNull($statusUpdate);
        $this->assertSame(WebhookLogService::STATUS_PROCESSED, $statusUpdate['status']);
        $this->assertSame($contractId, $statusUpdate['contractId']);
    }

    public function testMarkEventFailedUpdatesStatusWithError(): void
    {
        $eventId = 'evt_to_fail';
        $errorMessage = 'Invalid signature verification';
        $this->service->logEventReceived($eventId, 'payment_intent.failed', []);

        $this->service->markEventFailed($eventId, $errorMessage);

        $statusUpdate = $this->repository->getStatusUpdate($eventId);
        $this->assertNotNull($statusUpdate);
        $this->assertSame(WebhookLogService::STATUS_FAILED, $statusUpdate['status']);
        $this->assertSame($errorMessage, $statusUpdate['error']);
    }

    public function testEventExistsReturnsTrueForExistingEvent(): void
    {
        $eventId = 'evt_exists_test';
        $this->service->logEventReceived($eventId, 'charge.captured', []);

        $this->assertTrue($this->service->eventExists($eventId));
    }

    public function testEventExistsReturnsFalseForNonExistingEvent(): void
    {
        $this->assertFalse($this->service->eventExists('evt_nonexistent'));
    }

    public function testFindByEventIdReturnsWebhookLog(): void
    {
        $eventId = 'evt_find_test';
        $eventType = 'checkout.session.completed';
        $this->service->logEventReceived($eventId, $eventType, ['session_id' => 'cs_test']);

        $found = $this->service->findByEventId($eventId);

        $this->assertNotNull($found);
        $this->assertSame($eventId, $found->getEventId());
        $this->assertSame($eventType, $found->getEventType());
    }

    public function testFindByEventIdReturnsNullForNonExistingEvent(): void
    {
        $found = $this->service->findByEventId('evt_not_found');

        $this->assertNull($found);
    }

    public function testStatusConstants(): void
    {
        $this->assertSame('received', WebhookLogService::STATUS_RECEIVED);
        $this->assertSame('processed', WebhookLogService::STATUS_PROCESSED);
        $this->assertSame('failed', WebhookLogService::STATUS_FAILED);
    }
}
