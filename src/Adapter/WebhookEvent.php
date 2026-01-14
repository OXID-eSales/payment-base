<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Adapter;

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
     * Get the event ID (unique identifier from provider).
     *
     * @return string Event ID
     */
    public function getEventId(): string;

    /**
     * Get the event type (normalized).
     *
     * Standard event types:
     * - 'payment.authorized'
     * - 'payment.captured'
     * - 'payment.failed'
     * - 'payment.refunded'
     * - 'payment.cancelled'
     *
     * @return string Event type
     */
    public function getEventType(): string;

    /**
     * Get the provider-specific event type.
     *
     * Examples:
     * - Stripe: 'payment_intent.succeeded'
     * - PayPal: 'PAYMENT.CAPTURE.COMPLETED'
     * - Unzer: 'charge.succeeded'
     *
     * @return string Provider-specific event type
     */
    public function getProviderEventType(): string;

    /**
     * Get the payment ID associated with this event.
     *
     * @return string|null Provider payment ID, null if not applicable
     */
    public function getPaymentId(): ?string;

    /**
     * Get the event data payload.
     *
     * @return array<string, mixed> Event data
     */
    public function getData(): array;

    /**
     * Get the event creation timestamp.
     *
     * @return \DateTimeInterface When the event occurred
     */
    public function getCreatedAt(): \DateTimeInterface;

    /**
     * Check if the webhook signature is valid.
     *
     * @return bool True if signature was validated
     */
    public function isVerified(): bool;

    /**
     * Get the raw webhook payload.
     *
     * @return string Raw payload as received
     */
    public function getRawPayload(): string;
}
