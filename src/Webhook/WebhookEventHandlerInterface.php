<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Webhook;

/**
 * Interface for webhook event handlers.
 *
 * Handlers implement specific business logic for different webhook event types.
 * Each handler declares which event types it supports and processes those events.
 *
 * @since Sprint 13
 */
interface WebhookEventHandlerInterface
{
    /**
     * Check if this handler supports the given event type.
     *
     * @param string $eventType The webhook event type (e.g., 'payment_intent.succeeded')
     * @return bool True if this handler can process the event type
     */
    public function supports(string $eventType): bool;

    /**
     * Handle the webhook event.
     *
     * @param WebhookEvent $event The verified webhook event
     * @return WebhookResult The result of processing
     */
    public function handle(WebhookEvent $event): WebhookResult;
}
