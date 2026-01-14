<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Webhook;

/**
 * Value object representing a verified webhook event.
 *
 * Immutable DTO containing parsed event data after signature verification.
 * Provides helper methods for accessing common event properties.
 *
 * @since Sprint 13
 */
final readonly class WebhookEvent
{
    /**
     * @param string $id Event ID (e.g., 'evt_xxx')
     * @param string $type Event type (e.g., 'payment_intent.succeeded')
     * @param array<string, mixed> $data Event data payload
     * @param int $created Unix timestamp when event was created
     */
    public function __construct(
        public string $id,
        public string $type,
        public array $data,
        public int $created
    ) {
    }

    /**
     * Check if event matches a specific type.
     */
    public function isType(string $type): bool
    {
        return $this->type === $type;
    }

    /**
     * Get the object ID from the event data.
     *
     * @return string|null The object ID or null if not present
     */
    public function getObjectId(): ?string
    {
        $object = $this->getObject();
        $id = $object['id'] ?? null;

        return is_string($id) ? $id : null;
    }

    /**
     * Get the data object from the event.
     *
     * @return array<string, mixed>
     */
    public function getObject(): array
    {
        $object = $this->data['object'] ?? [];

        /** @var array<string, mixed> */
        return is_array($object) ? $object : [];
    }
}
