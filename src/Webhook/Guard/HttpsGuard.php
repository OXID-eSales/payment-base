<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Webhook\Guard;

final class HttpsGuard implements WebhookGuardInterface
{
    public function __construct(private readonly bool $allowHttpForDevelopment = false)
    {
    }

    public function check(WebhookHttpRequest $request): WebhookGuardResult
    {
        if (strtolower($request->scheme) === 'https' || $this->allowHttpForDevelopment) {
            return WebhookGuardResult::pass();
        }

        return WebhookGuardResult::reject(400, 'tls_required');
    }
}
