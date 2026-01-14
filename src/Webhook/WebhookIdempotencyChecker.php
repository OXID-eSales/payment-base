<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Webhook;

use DateTimeImmutable;
use OxidEsales\PaymentComponent\Repository\WebhookLogRepositoryInterface;

class WebhookIdempotencyChecker implements WebhookIdempotencyCheckerInterface
{
    /**
     * @var array<string, true>
     */
    private array $processedEvents = [];

    public function __construct(
        private readonly WebhookLogRepositoryInterface $logRepository
    ) {
    }

    public function isProcessed(string $eventId): bool
    {
        if (isset($this->processedEvents[$eventId])) {
            return true;
        }

        return $this->logRepository->existsByEventId($eventId);
    }

    public function markAsProcessed(string $eventId): void
    {
        $this->processedEvents[$eventId] = true;

        $log = new WebhookLog(
            $eventId,
            new DateTimeImmutable(),
            'processed'
        );

        $this->logRepository->save($log);
    }
}
