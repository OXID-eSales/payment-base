<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Webhook;

use DateTimeImmutable;
use OxidEsales\PaymentComponent\Repository\WebhookLogRepositoryInterface;
use OxidEsales\PaymentComponent\Webhook\AbstractWebhookProcessor;
use OxidEsales\PaymentComponent\Webhook\Exception\WebhookSignatureException;
use OxidEsales\PaymentComponent\Webhook\WebhookEvent;
use OxidEsales\PaymentComponent\Webhook\WebhookLog;
use OxidEsales\PaymentComponent\Webhook\WebhookRequest;
use OxidEsales\PaymentComponent\Webhook\WebhookResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for AbstractWebhookProcessor Template Method pattern.
 *
 * @covers \OxidEsales\PaymentComponent\Webhook\AbstractWebhookProcessor
 */
class AbstractWebhookProcessorTest extends TestCase
{
    private WebhookLogRepositoryInterface&MockObject $logRepository;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->logRepository = $this->createMock(WebhookLogRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testProcessSuccessfulWebhook(): void
    {
        // Arrange
        $request = new WebhookRequest(
            payload: '{"id":"evt_123","type":"payment_intent.succeeded"}',
            signature: 'valid_signature',
            remoteIp: '127.0.0.1',
            receivedAt: new DateTimeImmutable()
        );

        $event = new WebhookEvent(
            id: 'evt_123',
            type: 'payment_intent.succeeded',
            data: ['object' => ['id' => 'pi_123']],
            created: time()
        );

        $this->logRepository->expects($this->once())
            ->method('existsByEventId')
            ->with('evt_123')
            ->willReturn(false);

        $this->logRepository->expects($this->once())
            ->method('save')
            ->with($this->isInstanceOf(WebhookLog::class));

        $this->logRepository->expects($this->once())
            ->method('updateStatus')
            ->with('evt_123', 'processed', $this->anything());

        $processor = $this->createTestProcessor($event, WebhookResult::success('fulfilled'));

        // Act
        $result = $processor->process($request);

        // Assert
        $this->assertTrue($result->isSuccess());
        $this->assertSame('fulfilled', $result->action);
    }

    public function testProcessSkipsAlreadyProcessedWebhook(): void
    {
        // Arrange
        $request = new WebhookRequest(
            payload: '{"id":"evt_123","type":"payment_intent.succeeded"}',
            signature: 'valid_signature',
            remoteIp: '127.0.0.1',
            receivedAt: new DateTimeImmutable()
        );

        $event = new WebhookEvent(
            id: 'evt_123',
            type: 'payment_intent.succeeded',
            data: ['object' => ['id' => 'pi_123']],
            created: time()
        );

        $this->logRepository->expects($this->once())
            ->method('existsByEventId')
            ->with('evt_123')
            ->willReturn(true); // Already processed

        $this->logRepository->expects($this->never())
            ->method('save');

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('already processed'),
                $this->arrayHasKey('eventId')
            );

        $processor = $this->createTestProcessor($event, WebhookResult::success('fulfilled'));

        // Act
        $result = $processor->process($request);

        // Assert
        $this->assertTrue($result->isSuccess());
        $this->assertSame('skipped', $result->action);
        $this->assertSame('Already processed', $result->error);
    }

    public function testProcessReturnsFailureOnInvalidSignature(): void
    {
        // Arrange
        $request = new WebhookRequest(
            payload: '{"id":"evt_123"}',
            signature: 'invalid_signature',
            remoteIp: '127.0.0.1',
            receivedAt: new DateTimeImmutable()
        );

        $this->logRepository->expects($this->never())
            ->method('existsByEventId');

        $this->logRepository->expects($this->never())
            ->method('save');

        $processor = $this->createTestProcessorWithSignatureFailure('Invalid webhook signature');

        // Act
        $result = $processor->process($request);

        // Assert
        $this->assertTrue($result->isFailure());
        $this->assertSame('signature_invalid', $result->action);
        $this->assertStringContainsString('Invalid webhook signature', $result->error ?? '');
    }

    public function testProcessLogsWebhookOnReceive(): void
    {
        // Arrange
        $receivedAt = new DateTimeImmutable('2026-01-21 10:00:00');
        $request = new WebhookRequest(
            payload: '{"id":"evt_456","type":"charge.refunded"}',
            signature: 'valid_signature',
            remoteIp: '192.168.1.1',
            receivedAt: $receivedAt
        );

        $event = new WebhookEvent(
            id: 'evt_456',
            type: 'charge.refunded',
            data: ['object' => ['id' => 'ch_123', 'amount_refunded' => 1000]],
            created: time()
        );

        $this->logRepository->expects($this->once())
            ->method('existsByEventId')
            ->willReturn(false);

        $savedLog = null;
        $this->logRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(function (WebhookLog $log) use (&$savedLog) {
                $savedLog = $log;
                return true;
            }));

        $processor = $this->createTestProcessor($event, WebhookResult::success('refunded'));

        // Act
        $processor->process($request);

        // Assert
        $this->assertNotNull($savedLog);
        $this->assertSame('evt_456', $savedLog->getEventId());
        $this->assertSame('charge.refunded', $savedLog->getEventType());
        $this->assertSame('test_provider', $savedLog->getProvider());
        $this->assertSame('received', $savedLog->getStatus());
    }

    public function testProcessUpdatesStatusOnSuccess(): void
    {
        // Arrange
        $request = new WebhookRequest(
            payload: '{"id":"evt_789"}',
            signature: 'valid',
            remoteIp: '127.0.0.1',
            receivedAt: new DateTimeImmutable()
        );

        $event = new WebhookEvent(
            id: 'evt_789',
            type: 'payment_intent.succeeded',
            data: [],
            created: time()
        );

        $this->logRepository->expects($this->once())
            ->method('existsByEventId')
            ->willReturn(false);

        $this->logRepository->expects($this->once())
            ->method('updateStatus')
            ->with('evt_789', 'processed', $this->anything());

        $processor = $this->createTestProcessor($event, WebhookResult::success('fulfilled'));

        // Act
        $result = $processor->process($request);

        // Assert
        $this->assertTrue($result->isSuccess());
    }

    public function testProcessUpdatesStatusOnFailure(): void
    {
        // Arrange
        $request = new WebhookRequest(
            payload: '{"id":"evt_fail"}',
            signature: 'valid',
            remoteIp: '127.0.0.1',
            receivedAt: new DateTimeImmutable()
        );

        $event = new WebhookEvent(
            id: 'evt_fail',
            type: 'payment_intent.failed',
            data: [],
            created: time()
        );

        $this->logRepository->expects($this->once())
            ->method('existsByEventId')
            ->willReturn(false);

        $this->logRepository->expects($this->once())
            ->method('updateStatus')
            ->with('evt_fail', 'failed', $this->anything());

        $processor = $this->createTestProcessor($event, WebhookResult::failure('contract_failed', 'Payment declined'));

        // Act
        $result = $processor->process($request);

        // Assert
        $this->assertTrue($result->isFailure());
    }

    public function testProcessHandlesExceptionInProcessEvent(): void
    {
        // Arrange
        $request = new WebhookRequest(
            payload: '{"id":"evt_exception"}',
            signature: 'valid',
            remoteIp: '127.0.0.1',
            receivedAt: new DateTimeImmutable()
        );

        $event = new WebhookEvent(
            id: 'evt_exception',
            type: 'payment_intent.succeeded',
            data: [],
            created: time()
        );

        $this->logRepository->expects($this->once())
            ->method('existsByEventId')
            ->willReturn(false);

        $this->logRepository->expects($this->once())
            ->method('updateStatus')
            ->with('evt_exception', 'failed', $this->anything());

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('processing failed'),
                $this->arrayHasKey('error')
            );

        $processor = $this->createTestProcessorWithProcessingException(
            $event,
            new \RuntimeException('Database connection failed')
        );

        // Act
        $result = $processor->process($request);

        // Assert
        $this->assertTrue($result->isFailure());
        $this->assertSame('processing_failed', $result->action);
        $this->assertStringContainsString('Database connection failed', $result->error ?? '');
    }

    public function testProcessPassesContractIdToUpdateStatus(): void
    {
        // Arrange
        $request = new WebhookRequest(
            payload: '{"id":"evt_contract"}',
            signature: 'valid',
            remoteIp: '127.0.0.1',
            receivedAt: new DateTimeImmutable()
        );

        $event = new WebhookEvent(
            id: 'evt_contract',
            type: 'payment_intent.succeeded',
            data: [],
            created: time()
        );

        $this->logRepository->expects($this->once())
            ->method('existsByEventId')
            ->willReturn(false);

        // updateStatus(eventId, status, error, contractId)
        $this->logRepository->expects($this->once())
            ->method('updateStatus')
            ->with('evt_contract', 'processed', null, 'contract_abc123');

        $resultWithContractId = WebhookResult::success('fulfilled');

        $processor = $this->createTestProcessorWithContractId($event, $resultWithContractId, 'contract_abc123');

        // Act
        $processor->process($request);
    }

    /**
     * Create a test processor with controlled behavior.
     */
    private function createTestProcessor(WebhookEvent $event, WebhookResult $result): AbstractWebhookProcessor
    {
        return new class (
            $this->logRepository,
            $this->logger,
            $event,
            $result
        ) extends AbstractWebhookProcessor {
            public function __construct(
                WebhookLogRepositoryInterface $logRepository,
                LoggerInterface $logger,
                private readonly WebhookEvent $testEvent,
                private readonly WebhookResult $testResult
            ) {
                parent::__construct($logRepository, $logger);
            }

            protected function getProviderName(): string
            {
                return 'test_provider';
            }

            protected function parseAndValidateRequest(WebhookRequest $request): WebhookEvent
            {
                return $this->testEvent;
            }

            protected function processEvent(WebhookEvent $event): WebhookResult
            {
                return $this->testResult;
            }

            protected function getContractIdFromResult(WebhookResult $result): ?string
            {
                return null;
            }
        };
    }

    /**
     * Create a test processor that throws WebhookSignatureException.
     */
    private function createTestProcessorWithSignatureFailure(string $message): AbstractWebhookProcessor
    {
        return new class (
            $this->logRepository,
            $this->logger,
            $message
        ) extends AbstractWebhookProcessor {
            public function __construct(
                WebhookLogRepositoryInterface $logRepository,
                LoggerInterface $logger,
                private readonly string $errorMessage
            ) {
                parent::__construct($logRepository, $logger);
            }

            protected function getProviderName(): string
            {
                return 'test_provider';
            }

            protected function parseAndValidateRequest(WebhookRequest $request): WebhookEvent
            {
                throw new WebhookSignatureException($this->errorMessage);
            }

            protected function processEvent(WebhookEvent $event): WebhookResult
            {
                return WebhookResult::success('should_not_reach');
            }

            protected function getContractIdFromResult(WebhookResult $result): ?string
            {
                return null;
            }
        };
    }

    /**
     * Create a test processor that throws exception in processEvent.
     */
    private function createTestProcessorWithProcessingException(
        WebhookEvent $event,
        \Exception $exception
    ): AbstractWebhookProcessor {
        return new class (
            $this->logRepository,
            $this->logger,
            $event,
            $exception
        ) extends AbstractWebhookProcessor {
            public function __construct(
                WebhookLogRepositoryInterface $logRepository,
                LoggerInterface $logger,
                private readonly WebhookEvent $testEvent,
                private readonly \Exception $testException
            ) {
                parent::__construct($logRepository, $logger);
            }

            protected function getProviderName(): string
            {
                return 'test_provider';
            }

            protected function parseAndValidateRequest(WebhookRequest $request): WebhookEvent
            {
                return $this->testEvent;
            }

            protected function processEvent(WebhookEvent $event): WebhookResult
            {
                throw $this->testException;
            }

            protected function getContractIdFromResult(WebhookResult $result): ?string
            {
                return null;
            }
        };
    }

    /**
     * Create a test processor that returns a contract ID.
     */
    private function createTestProcessorWithContractId(
        WebhookEvent $event,
        WebhookResult $result,
        string $contractId
    ): AbstractWebhookProcessor {
        return new class (
            $this->logRepository,
            $this->logger,
            $event,
            $result,
            $contractId
        ) extends AbstractWebhookProcessor {
            public function __construct(
                WebhookLogRepositoryInterface $logRepository,
                LoggerInterface $logger,
                private readonly WebhookEvent $testEvent,
                private readonly WebhookResult $testResult,
                private readonly string $testContractId
            ) {
                parent::__construct($logRepository, $logger);
            }

            protected function getProviderName(): string
            {
                return 'test_provider';
            }

            protected function parseAndValidateRequest(WebhookRequest $request): WebhookEvent
            {
                return $this->testEvent;
            }

            protected function processEvent(WebhookEvent $event): WebhookResult
            {
                return $this->testResult;
            }

            protected function getContractIdFromResult(WebhookResult $result): ?string
            {
                return $this->testContractId;
            }
        };
    }
}
