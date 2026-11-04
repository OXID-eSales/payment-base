<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter;

/**
 * Interface for webhook events from payment providers.
 *
 * Provides provider-agnostic access to webhook event data.
 * Each provider adapter implements this interface for their webhook events.
 *
 * @since 1.0.0
 */
interface WebhookEvent
{
    /**
     * Get the payment ID associated with this event.
     *
     * @return string|null Provider payment ID, null if not applicable
     */
    public function getPaymentId(): ?string;
}
