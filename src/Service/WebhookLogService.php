<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Service;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Repository\WebhookLogRepositoryInterface;
use OxidEsales\PaymentBase\Webhook\WebhookLog;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for webhook log operations.
 *
 * Sprint 7 Phase 4: Provides proper layering for webhook log access.
 * All webhook log operations should go through this service.
 *
 * Architecture:
 * Controller/Service → WebhookLogService → WebhookLogRepository → Database
 */
class WebhookLogService implements WebhookLogServiceInterface
{
    public const STATUS_RECEIVED = 'received';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED = 'failed';

    private LoggerInterface $logger;

    public function __construct(
        private readonly WebhookLogRepositoryInterface $repository,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function logEventReceived(
        string $eventId,
        string $eventType,
        array $payload,
        string $provider = 'stripe'
    ): WebhookLog {
        $log = new WebhookLog(
            $eventId,
            new DateTimeImmutable(),
            self::STATUS_RECEIVED
        );

        $log->setEventType($eventType);
        $log->setProvider($provider);
        $log->setPayload($payload);

        try {
            $this->repository->save($log);

            $this->logger->info('Webhook event received', [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'provider' => $provider,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to log webhook event', [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $log;
    }

    public function markEventProcessed(string $eventId, ?string $contractId = null): void
    {
        try {
            $this->repository->updateStatus(
                $eventId,
                self::STATUS_PROCESSED,
                null,
                $contractId
            );

            $this->logger->info('Webhook event processed', [
                'event_id' => $eventId,
                'contract_id' => $contractId,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to mark webhook event as processed', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function markEventFailed(string $eventId, string $errorMessage): void
    {
        try {
            $this->repository->updateStatus(
                $eventId,
                self::STATUS_FAILED,
                $errorMessage
            );

            $this->logger->warning('Webhook event failed', [
                'event_id' => $eventId,
                'error' => $errorMessage,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to mark webhook event as failed', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function eventExists(string $eventId): bool
    {
        return $this->repository->existsByEventId($eventId);
    }

    public function findByEventId(string $eventId): ?WebhookLog
    {
        return $this->repository->findByEventId($eventId);
    }
}
