<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Repository;

use OxidEsales\PaymentComponent\Webhook\WebhookLog;

/**
 * In-memory implementation of WebhookLogRepository.
 * Used for testing and fallback scenarios.
 */
class WebhookLogRepository implements WebhookLogRepositoryInterface
{
    /**
     * @var array<string, WebhookLog>
     */
    private array $logs = [];

    /**
     * @var array<string, string>
     */
    private array $eventIndex = [];

    /**
     * @var array<string, array{status: string, error: ?string, contractId: ?string}>
     */
    private array $statusUpdates = [];

    public function save(WebhookLog $log): void
    {
        $this->logs[$log->getId()] = $log;
        $this->eventIndex[$log->getEventId()] = $log->getId();
    }

    public function existsByEventId(string $eventId): bool
    {
        return isset($this->eventIndex[$eventId]);
    }

    public function findByEventId(string $eventId): ?WebhookLog
    {
        $logId = $this->eventIndex[$eventId] ?? null;
        return $logId ? $this->logs[$logId] : null;
    }

    public function updateStatus(
        string $eventId,
        string $status,
        ?string $error = null,
        ?string $contractId = null
    ): void {
        $this->statusUpdates[$eventId] = [
            'status' => $status,
            'error' => $error,
            'contractId' => $contractId,
        ];
    }

    /**
     * Get status update for testing.
     *
     * @return array{status: string, error: ?string, contractId: ?string}|null
     */
    public function getStatusUpdate(string $eventId): ?array
    {
        return $this->statusUpdates[$eventId] ?? null;
    }
}
