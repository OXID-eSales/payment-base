<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Webhook;

use OxidEsales\PaymentComponent\Webhook\WebhookEvent;
use OxidEsales\PaymentComponent\Webhook\WebhookEventDispatcher;
use OxidEsales\PaymentComponent\Webhook\WebhookEventDispatcherInterface;
use OxidEsales\PaymentComponent\Webhook\WebhookEventHandlerInterface;
use OxidEsales\PaymentComponent\Webhook\WebhookResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OxidEsales\PaymentComponent\Webhook\WebhookEventDispatcher
 * @group sprint-13
 * @group webhook
 */
final class WebhookEventDispatcherTest extends TestCase
{
    private LoggerInterface $logger;
    private WebhookEventDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->dispatcher = new WebhookEventDispatcher($this->logger);
    }

    /**
     * @test
     */
    public function implementsInterface(): void
    {
        $this->assertInstanceOf(WebhookEventDispatcherInterface::class, $this->dispatcher);
    }

    /**
     * @test
     */
    public function dispatchesToCorrectHandler(): void
    {
        $event = new WebhookEvent('evt_123', 'payment_intent.succeeded', [], 0);

        $handler = $this->createMock(WebhookEventHandlerInterface::class);
        $handler->method('supports')->with('payment_intent.succeeded')->willReturn(true);
        $handler->expects($this->once())
            ->method('handle')
            ->with($event)
            ->willReturn(WebhookResult::success('handled'));

        $this->dispatcher->registerHandler($handler);

        $result = $this->dispatcher->dispatch($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('handled', $result->action);
    }

    /**
     * @test
     */
    public function returnsSuccessWhenHandlerSucceeds(): void
    {
        $event = new WebhookEvent('evt_123', 'charge.refunded', [], 0);

        $handler = $this->createMock(WebhookEventHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willReturn(WebhookResult::success('refund_processed'));

        $this->dispatcher->registerHandler($handler);

        $result = $this->dispatcher->dispatch($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('refund_processed', $result->action);
    }

    /**
     * @test
     */
    public function returnsSkippedWhenNoHandlerFound(): void
    {
        $event = new WebhookEvent('evt_123', 'unknown.event', [], 0);

        $result = $this->dispatcher->dispatch($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('skipped', $result->action);
        $this->assertStringContainsString('No handler', $result->error);
    }

    /**
     * @test
     */
    public function logsAllDispatchedEvents(): void
    {
        $event = new WebhookEvent('evt_123', 'payment_intent.succeeded', [], 0);

        $loggedMessages = [];
        $this->logger->expects($this->atLeastOnce())
            ->method('info')
            ->willReturnCallback(function ($message, $context) use (&$loggedMessages) {
                $loggedMessages[] = ['message' => $message, 'context' => $context];
            });

        $this->dispatcher->dispatch($event);

        $dispatchLog = array_filter($loggedMessages, fn($log) => str_contains($log['message'], 'Dispatching'));
        $this->assertNotEmpty($dispatchLog, 'Should log dispatching event');

        $firstLog = reset($dispatchLog);
        $this->assertSame('evt_123', $firstLog['context']['event_id']);
    }

    /**
     * @test
     */
    public function skipsHandlersThatDoNotSupport(): void
    {
        $event = new WebhookEvent('evt_123', 'payment_intent.succeeded', [], 0);

        $nonSupportingHandler = $this->createMock(WebhookEventHandlerInterface::class);
        $nonSupportingHandler->method('supports')->willReturn(false);
        $nonSupportingHandler->expects($this->never())->method('handle');

        $supportingHandler = $this->createMock(WebhookEventHandlerInterface::class);
        $supportingHandler->method('supports')->willReturn(true);
        $supportingHandler->expects($this->once())
            ->method('handle')
            ->willReturn(WebhookResult::success('handled'));

        $this->dispatcher->registerHandler($nonSupportingHandler);
        $this->dispatcher->registerHandler($supportingHandler);

        $result = $this->dispatcher->dispatch($event);

        $this->assertTrue($result->isSuccess());
    }

    /**
     * @test
     */
    public function returnsFailureWhenHandlerFails(): void
    {
        $event = new WebhookEvent('evt_123', 'payment_intent.succeeded', [], 0);

        $handler = $this->createMock(WebhookEventHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willReturn(WebhookResult::failure('error', 'Handler failed'));

        $this->dispatcher->registerHandler($handler);

        $result = $this->dispatcher->dispatch($event);

        $this->assertTrue($result->isFailure());
        $this->assertSame('error', $result->action);
    }

    /**
     * @test
     */
    public function catchesHandlerExceptionsAndReturnsFailure(): void
    {
        $event = new WebhookEvent('evt_123', 'payment_intent.succeeded', [], 0);

        $handler = $this->createMock(WebhookEventHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willThrowException(new \RuntimeException('Unexpected error'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Handler exception'),
                $this->callback(fn($ctx) => isset($ctx['exception']))
            );

        $this->dispatcher->registerHandler($handler);

        $result = $this->dispatcher->dispatch($event);

        $this->assertTrue($result->isFailure());
        $this->assertSame('exception', $result->action);
        $this->assertStringContainsString('Unexpected error', $result->error);
    }

    /**
     * @test
     * Sprint 17: Fixed false-positive test - now verifies both handlers are checked
     */
    public function canRegisterMultipleHandlers(): void
    {
        $event = new WebhookEvent('evt_123', 'some.event', [], 0);

        // First handler doesn't support the event
        $handler1 = $this->createMock(WebhookEventHandlerInterface::class);
        $handler1->expects($this->once())
            ->method('supports')
            ->with('some.event')
            ->willReturn(false);
        $handler1->expects($this->never())->method('handle');

        // Second handler supports and handles the event
        $handler2 = $this->createMock(WebhookEventHandlerInterface::class);
        $handler2->expects($this->once())
            ->method('supports')
            ->with('some.event')
            ->willReturn(true);
        $handler2->expects($this->once())
            ->method('handle')
            ->willReturn(WebhookResult::success('handled_by_second'));

        $this->dispatcher->registerHandler($handler1);
        $this->dispatcher->registerHandler($handler2);

        $result = $this->dispatcher->dispatch($event);

        // Assert: Second handler was reached and handled the event
        $this->assertTrue($result->isSuccess());
        $this->assertSame('handled_by_second', $result->action);
    }

    /**
     * @test
     */
    public function firstMatchingHandlerWins(): void
    {
        $event = new WebhookEvent('evt_123', 'payment_intent.succeeded', [], 0);

        $handler1 = $this->createMock(WebhookEventHandlerInterface::class);
        $handler1->method('supports')->willReturn(true);
        $handler1->expects($this->once())
            ->method('handle')
            ->willReturn(WebhookResult::success('first'));

        $handler2 = $this->createMock(WebhookEventHandlerInterface::class);
        $handler2->method('supports')->willReturn(true);
        $handler2->expects($this->never())->method('handle');

        $this->dispatcher->registerHandler($handler1);
        $this->dispatcher->registerHandler($handler2);

        $result = $this->dispatcher->dispatch($event);

        $this->assertSame('first', $result->action);
    }
}
