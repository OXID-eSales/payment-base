<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Service;

use OxidEsales\PaymentComponent\Webhook\WebhookLog;

/**
 * Service interface for webhook log operations.
 *
 * Sprint 7 Phase 4: Provides proper layering for webhook log access.
 * All webhook log operations should go through this service.
 *
 * Architecture:
 * Controller/Service → WebhookLogService → WebhookLogRepository → Database
 */
interface WebhookLogServiceInterface
{
    /**
     * Log a received webhook event.
     *
     * Creates a new webhook log entry with status 'received'.
     *
     * @param string $eventId Stripe event ID
     * @param string $eventType Event type (e.g., 'payment_intent.succeeded')
     * @param array<string, mixed> $payload Raw webhook payload
     * @param string $provider Payment provider (default: 'stripe')
     * @return WebhookLog The created log entry
     */
    public function logEventReceived(
        string $eventId,
        string $eventType,
        array $payload,
        string $provider = 'stripe'
    ): WebhookLog;

    /**
     * Mark an event as successfully processed.
     *
     * Updates the webhook log status to 'processed' and sets processedAt timestamp.
     *
     * @param string $eventId Stripe event ID
     * @param string|null $contractId Optional contract ID that was affected
     */
    public function markEventProcessed(string $eventId, ?string $contractId = null): void;

    /**
     * Mark an event as failed.
     *
     * Updates the webhook log status to 'failed' and records the error message.
     *
     * @param string $eventId Stripe event ID
     * @param string $errorMessage Description of what went wrong
     */
    public function markEventFailed(string $eventId, string $errorMessage): void;

    /**
     * Check if an event has already been received.
     *
     * Used for idempotency - to avoid processing the same webhook twice.
     *
     * @param string $eventId Stripe event ID
     * @return bool True if event already exists in the log
     */
    public function eventExists(string $eventId): bool;

    /**
     * Find a webhook log by event ID.
     *
     * @param string $eventId Stripe event ID
     * @return WebhookLog|null The log entry or null if not found
     */
    public function findByEventId(string $eventId): ?WebhookLog;
}
