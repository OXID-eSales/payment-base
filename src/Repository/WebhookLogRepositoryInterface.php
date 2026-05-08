<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Repository;

use OxidEsales\PaymentBase\Webhook\WebhookLog;

interface WebhookLogRepositoryInterface
{
    public function save(WebhookLog $log): void;

    public function existsByEventId(string $eventId): bool;

    public function findByEventId(string $eventId): ?WebhookLog;

    /**
     * Atomically claim an event for processing.
     *
     * Uses INSERT with unique key constraint — only one process can claim a given event ID.
     * Returns true if this caller claimed it, false if already claimed by another process.
     *
     * Replaces the TOCTOU-vulnerable existsByEventId() + save() pattern.
     *
     * @param string $eventId Unique event identifier (e.g., 'evt_xxx')
     * @param string $provider Provider name (e.g., 'stripe')
     * @param string $eventType Event type (e.g., 'payment_intent.succeeded')
     * @return bool True if event was claimed, false if already claimed
     */
    public function claimEvent(string $eventId, string $provider, string $eventType): bool;

    /**
     * Update webhook log status by event ID.
     *
     * @param string $eventId Stripe event ID
     * @param string $status New status (received, processed, failed)
     * @param string|null $error Optional error message (for failed status)
     * @param string|null $contractId Optional contract ID that was affected
     */
    public function updateStatus(
        string $eventId,
        string $status,
        ?string $error = null,
        ?string $contractId = null
    ): void;
}
