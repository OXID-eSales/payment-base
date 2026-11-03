<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Webhook\Guard;

/**
 * The larger of Content-Length and the body actually read counts: a caller
 * cannot slip a large body past a small header.
 */
final class PayloadSizeGuard implements WebhookGuardInterface
{
    public function __construct(private readonly int $maxBytes = 1_048_576)
    {
    }

    public function check(WebhookHttpRequest $request): WebhookGuardResult
    {
        if (max($request->contentLength, strlen($request->rawBody)) <= $this->maxBytes) {
            return WebhookGuardResult::pass();
        }

        return WebhookGuardResult::reject(413, 'payload_too_large');
    }
}
