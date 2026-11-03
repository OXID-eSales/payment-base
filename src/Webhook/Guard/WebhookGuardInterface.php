<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Webhook\Guard;

/**
 * VR-19 W2 — one check a webhook request must pass before its signature or
 * payload is looked at. Shared by the providers; replaces their three copies.
 *
 * @since 1.7.0
 */
interface WebhookGuardInterface
{
    public function check(WebhookHttpRequest $request): WebhookGuardResult;
}
