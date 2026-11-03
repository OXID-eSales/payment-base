<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Webhook\Guard;

/**
 * What the guards look at. `clientIp` is the caller as TrustedProxyClientIp
 * resolved it, not blindly REMOTE_ADDR.
 */
final readonly class WebhookHttpRequest
{
    public function __construct(
        public string $scheme,
        public string $clientIp,
        public int $contentLength,
        public string $rawBody,
    ) {
    }
}
