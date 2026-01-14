<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Webhook;

/**
 * Value object representing an incoming webhook HTTP request.
 *
 * Immutable DTO containing the raw payload, signature header,
 * and request metadata needed for verification and processing.
 *
 * @since Sprint 13
 */
final readonly class WebhookRequest
{
    public function __construct(
        public string $payload,
        public string $signature,
        public string $remoteIp,
        public \DateTimeImmutable $receivedAt
    ) {
    }

    /**
     * Check if request has a signature header.
     */
    public function hasSignature(): bool
    {
        return $this->signature !== '';
    }
}
