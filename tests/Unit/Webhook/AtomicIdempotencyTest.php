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
use OxidEsales\PaymentComponent\Webhook\WebhookEvent;
use OxidEsales\PaymentComponent\Webhook\WebhookRequest;
use OxidEsales\PaymentComponent\Webhook\WebhookResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Sprint 64g: Atomic idempotency tests for webhook processing.
 *
 * Verifies that AbstractWebhookProcessor uses claimEvent() instead of
 * the TOCTOU-vulnerable existsByEventId() + save() pattern.
 *
 * @covers \OxidEsales\PaymentComponent\Webhook\AbstractWebhookProcessor
 * @group sprint-64g
 * @group security
 * @group idempotency
 */
final class AtomicIdempotencyTest extends TestCase
{
    private WebhookLogRepositoryInterface&MockObject $logRepository;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->logRepository = $this->createMock(WebhookLogRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    /** @test */
    public function processorProceedsWhenClaimEventSucceeds(): void
    {
        $this->logRepository->expects($this->once())
            ->method('claimEvent')
            ->with('evt_test_123', 'test_provider', 'payment_intent.succeeded')
            ->willReturn(true);

        $event = new WebhookEvent('evt_test_123', 'payment_intent.succeeded', [], time());
        $processor = $this->createTestProcessor($event, WebhookResult::success('fulfilled'));

        $request = new WebhookRequest('{}', 'sig', '1.2.3.4', new DateTimeImmutable());
        $result = $processor->process($request);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('fulfilled', $result->action);
    }

    /** @test */
    public function processorSkipsWhenClaimEventFails(): void
    {
        $this->logRepository->expects($this->once())
            ->method('claimEvent')
            ->with('evt_duplicate', 'test_provider', 'payment_intent.succeeded')
            ->willReturn(false);

        $event = new WebhookEvent('evt_duplicate', 'payment_intent.succeeded', [], time());
        $processor = $this->createTestProcessor($event, WebhookResult::success('fulfilled'));

        $request = new WebhookRequest('{}', 'sig', '1.2.3.4', new DateTimeImmutable());
        $result = $processor->process($request);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('skipped', $result->action);
        $this->assertStringContainsString('Already processed', $result->error ?? '');
    }

    /** @test */
    public function processorNeverCallsDeprecatedExistsByEventId(): void
    {
        $this->logRepository->method('claimEvent')->willReturn(true);
        $this->logRepository->expects($this->never())->method('existsByEventId');

        $event = new WebhookEvent('evt_new', 'charge.captured', [], time());
        $processor = $this->createTestProcessor($event, WebhookResult::success('captured'));

        $request = new WebhookRequest('{}', 'sig', '1.2.3.4', new DateTimeImmutable());
        $processor->process($request);
    }

    /** @test */
    public function processorNeverCallsSaveForInitialLog(): void
    {
        $this->logRepository->method('claimEvent')->willReturn(true);
        $this->logRepository->expects($this->never())->method('save');

        $event = new WebhookEvent('evt_nosave', 'payment_intent.succeeded', [], time());
        $processor = $this->createTestProcessor($event, WebhookResult::success('fulfilled'));

        $request = new WebhookRequest('{}', 'sig', '1.2.3.4', new DateTimeImmutable());
        $processor->process($request);
    }

    /** @test */
    public function claimEventReceivesProviderNameAndEventType(): void
    {
        $this->logRepository->expects($this->once())
            ->method('claimEvent')
            ->with(
                'evt_typed',
                'test_provider',
                'charge.refunded'
            )
            ->willReturn(true);

        $event = new WebhookEvent('evt_typed', 'charge.refunded', [], time());
        $processor = $this->createTestProcessor($event, WebhookResult::success('refunded'));

        $request = new WebhookRequest('{}', 'sig', '1.2.3.4', new DateTimeImmutable());
        $processor->process($request);
    }

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
}
