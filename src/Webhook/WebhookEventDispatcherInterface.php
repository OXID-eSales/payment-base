<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Webhook;

/**
 * Interface for dispatching webhook events to handlers.
 *
 * The dispatcher routes verified webhook events to registered handlers
 * based on event type. Supports multiple handlers per event type.
 *
 * @since Sprint 13
 */
interface WebhookEventDispatcherInterface
{
    /**
     * Register a handler for webhook events.
     *
     * @param WebhookEventHandlerInterface $handler The handler to register
     */
    public function registerHandler(WebhookEventHandlerInterface $handler): void;

    /**
     * Dispatch an event to all supporting handlers.
     *
     * @param WebhookEvent $event The event to dispatch
     * @return WebhookResult The combined result of all handlers
     */
    public function dispatch(WebhookEvent $event): WebhookResult;
}
