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
     * Claims the event id for processing. True for a first delivery and for the retry of a delivery
     * that ended `failed`; false for a replay of a processed delivery or one still being processed.
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
