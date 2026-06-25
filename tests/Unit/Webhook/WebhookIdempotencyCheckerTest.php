<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Webhook;

use OxidEsales\PaymentBase\Repository\WebhookLogRepositoryInterface;
use OxidEsales\PaymentBase\Webhook\WebhookIdempotencyChecker;
use OxidEsales\PaymentBase\Webhook\WebhookLog;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(\OxidEsales\PaymentBase\Webhook\WebhookIdempotencyChecker::class)]
final class WebhookIdempotencyCheckerTest extends TestCase
{
    private WebhookLogRepositoryInterface&MockObject $logRepository;
    private WebhookIdempotencyChecker $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logRepository = $this->createMock(WebhookLogRepositoryInterface::class);
        $this->checker = new WebhookIdempotencyChecker($this->logRepository);
    }

    public function testAllowsFirstProcessing(): void
    {
        $this->logRepository->expects($this->once())
            ->method('existsByEventId')
            ->with('evt_new_123')
            ->willReturn(false);

        $isProcessed = $this->checker->isProcessed('evt_new_123');

        $this->assertFalse($isProcessed);
    }

    public function testDetectsDuplicateWebhook(): void
    {
        $eventId = 'evt_duplicate_123';

        $this->logRepository->expects($this->once())
            ->method('existsByEventId')
            ->with($eventId)
            ->willReturn(true);

        $isProcessed = $this->checker->isProcessed($eventId);

        $this->assertTrue($isProcessed);
    }

    public function testMarksWebhookAsProcessed(): void
    {
        $eventId = 'evt_mark_processed';

        $this->logRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(function (WebhookLog $log) use ($eventId) {
                return $log->getEventId() === $eventId;
            }));

        $this->checker->markAsProcessed($eventId);
    }

    public function testDifferentWebhooksAreIndependent(): void
    {
        $this->logRepository->expects($this->exactly(2))
            ->method('existsByEventId')
            ->willReturnCallback(function (string $eventId) {
                return $eventId === 'evt_1';
            });

        $isProcessed1 = $this->checker->isProcessed('evt_1');
        $isProcessed2 = $this->checker->isProcessed('evt_2');

        $this->assertTrue($isProcessed1);
        $this->assertFalse($isProcessed2);
    }

    public function testUsesRepositoryForPersistence(): void
    {
        $eventId = 'evt_persistent';

        $this->logRepository->expects($this->once())
            ->method('save')
            ->with($this->isInstanceOf(WebhookLog::class));

        $this->checker->markAsProcessed($eventId);
    }
}
