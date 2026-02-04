<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Service;

use OxidEsales\PaymentComponent\Repository\WebhookLogRepositoryInterface;
use OxidEsales\PaymentComponent\Service\WebhookLogService;
use OxidEsales\PaymentComponent\Webhook\WebhookLog;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OxidEsales\PaymentComponent\Service\WebhookLogService
 */
final class WebhookLogServiceTest extends TestCase
{
    private WebhookLogRepositoryInterface&MockObject $repository;
    private LoggerInterface&MockObject $logger;
    private WebhookLogService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createMock(WebhookLogRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new WebhookLogService($this->repository, $this->logger);
    }

    public function testLogEventReceivedCreatesWebhookLog(): void
    {
        $eventId = 'evt_test_123';
        $eventType = 'payment_intent.succeeded';
        $payload = ['id' => 'pi_test', 'amount' => 1000];

        $this->repository->expects($this->once())
            ->method('save')
            ->with($this->isInstanceOf(WebhookLog::class));

        $result = $this->service->logEventReceived($eventId, $eventType, $payload);

        $this->assertInstanceOf(WebhookLog::class, $result);
        $this->assertSame($eventId, $result->getEventId());
        $this->assertSame($eventType, $result->getEventType());
        $this->assertSame('stripe', $result->getProvider());
        $this->assertSame(WebhookLogService::STATUS_RECEIVED, $result->getStatus());
        $this->assertSame($payload, $result->getPayload());
    }

    public function testLogEventReceivedWithCustomProvider(): void
    {
        $eventId = 'evt_custom_provider';
        $eventType = 'payment.completed';
        $payload = [];

        $this->repository->expects($this->once())
            ->method('save');

        $result = $this->service->logEventReceived($eventId, $eventType, $payload, 'unzer');

        $this->assertSame('unzer', $result->getProvider());
    }

    public function testMarkEventProcessedUpdatesStatus(): void
    {
        $eventId = 'evt_to_process';

        $this->repository->expects($this->once())
            ->method('updateStatus')
            ->with($eventId, WebhookLogService::STATUS_PROCESSED, null, null);

        $this->service->markEventProcessed($eventId);
    }

    public function testMarkEventProcessedWithContractId(): void
    {
        $eventId = 'evt_with_contract';
        $contractId = 'contract_abc123';

        $this->repository->expects($this->once())
            ->method('updateStatus')
            ->with($eventId, WebhookLogService::STATUS_PROCESSED, null, $contractId);

        $this->service->markEventProcessed($eventId, $contractId);
    }

    public function testMarkEventFailedUpdatesStatusWithError(): void
    {
        $eventId = 'evt_to_fail';
        $errorMessage = 'Invalid signature verification';

        $this->repository->expects($this->once())
            ->method('updateStatus')
            ->with($eventId, WebhookLogService::STATUS_FAILED, $errorMessage, null);

        $this->service->markEventFailed($eventId, $errorMessage);
    }

    public function testEventExistsReturnsTrueForExistingEvent(): void
    {
        $eventId = 'evt_exists_test';

        $this->repository->expects($this->once())
            ->method('existsByEventId')
            ->with($eventId)
            ->willReturn(true);

        $this->assertTrue($this->service->eventExists($eventId));
    }

    public function testEventExistsReturnsFalseForNonExistingEvent(): void
    {
        $this->repository->expects($this->once())
            ->method('existsByEventId')
            ->with('evt_nonexistent')
            ->willReturn(false);

        $this->assertFalse($this->service->eventExists('evt_nonexistent'));
    }

    public function testFindByEventIdReturnsWebhookLog(): void
    {
        $eventId = 'evt_find_test';
        $eventType = 'checkout.session.completed';

        $log = new WebhookLog($eventId, new \DateTimeImmutable(), 'received');
        $log->setEventType($eventType);

        $this->repository->expects($this->once())
            ->method('findByEventId')
            ->with($eventId)
            ->willReturn($log);

        $found = $this->service->findByEventId($eventId);

        $this->assertNotNull($found);
        $this->assertSame($eventId, $found->getEventId());
        $this->assertSame($eventType, $found->getEventType());
    }

    public function testFindByEventIdReturnsNullForNonExistingEvent(): void
    {
        $this->repository->expects($this->once())
            ->method('findByEventId')
            ->with('evt_not_found')
            ->willReturn(null);

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
