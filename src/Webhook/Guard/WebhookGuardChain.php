<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Webhook\Guard;

/**
 * Runs the guards in order; the first rejection is the answer.
 */
final class WebhookGuardChain implements WebhookGuardInterface
{
    /**
     * @param iterable<WebhookGuardInterface> $guards
     */
    public function __construct(private readonly iterable $guards)
    {
    }

    public function check(WebhookHttpRequest $request): WebhookGuardResult
    {
        foreach ($this->guards as $guard) {
            $result = $guard->check($request);
            if (!$result->ok) {
                return $result;
            }
        }

        return WebhookGuardResult::pass();
    }
}
