<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Webhook;

/**
 * The payload keys of one PSP that carry personal data and that the generic vocabulary of
 * WebhookPayloadSanitizer cannot recognise by name (a PayPal `payer`, a Mollie `consumerAccount`).
 *
 * Providers tag their implementation `payment_base.webhook_pii_key_provider`.
 */
interface WebhookPiiKeyProvider
{
    /**
     * Matched against a payload key as a whole, case-insensitively, at any depth.
     *
     * @return list<string>
     */
    public function piiKeys(): array;
}
