<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Webhook\Guard;

/**
 * Pass, or reject with the HTTP status the controller answers and a reason code
 * for the log (never shown to the caller beyond the status).
 */
final readonly class WebhookGuardResult
{
    private function __construct(
        public bool $ok,
        public int $httpStatus,
        public ?string $reason,
    ) {
    }

    public static function pass(): self
    {
        return new self(true, 200, null);
    }

    public static function reject(int $httpStatus, string $reason): self
    {
        return new self(false, $httpStatus, $reason);
    }
}
