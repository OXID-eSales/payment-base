<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Webhook;

use OxidEsales\PaymentComponent\Repository\WebhookLogRepository;
use OxidEsales\PaymentComponent\Webhook\WebhookIdempotencyChecker;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidEsales\PaymentComponent\Webhook\WebhookIdempotencyChecker
 */
final class WebhookIdempotencyCheckerTest extends TestCase
{
    private WebhookLogRepository $logRepository;
    private WebhookIdempotencyChecker $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logRepository = new WebhookLogRepository();
        $this->checker = new WebhookIdempotencyChecker($this->logRepository);
    }

    public function testAllowsFirstProcessing(): void
    {
        $isProcessed = $this->checker->isProcessed('evt_new_123');

        $this->assertFalse($isProcessed);
    }

    public function testDetectsDuplicateWebhook(): void
    {
        $eventId = 'evt_duplicate_123';

        $this->checker->markAsProcessed($eventId);
        $isProcessed = $this->checker->isProcessed($eventId);

        $this->assertTrue($isProcessed);
    }

    public function testMarksWebhookAsProcessed(): void
    {
        $eventId = 'evt_mark_processed';

        $this->assertFalse($this->checker->isProcessed($eventId));

        $this->checker->markAsProcessed($eventId);

        $this->assertTrue($this->checker->isProcessed($eventId));
    }

    public function testDifferentWebhooksAreIndependent(): void
    {
        $this->checker->markAsProcessed('evt_1');

        $isProcessed1 = $this->checker->isProcessed('evt_1');
        $isProcessed2 = $this->checker->isProcessed('evt_2');

        $this->assertTrue($isProcessed1);
        $this->assertFalse($isProcessed2);
    }

    public function testUsesRepositoryForPersistence(): void
    {
        $eventId = 'evt_persistent';

        $this->checker->markAsProcessed($eventId);

        $exists = $this->logRepository->existsByEventId($eventId);
        $this->assertTrue($exists);
    }

    public function testDetectsProcessedEventFromRepository(): void
    {
        $eventId = 'evt_from_db';

        $this->checker->markAsProcessed($eventId);

        $newChecker = new WebhookIdempotencyChecker($this->logRepository);
        $isProcessed = $newChecker->isProcessed($eventId);

        $this->assertTrue($isProcessed);
    }
}
