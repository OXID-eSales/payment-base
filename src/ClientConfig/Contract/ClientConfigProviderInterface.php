<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\ClientConfig\Contract;

/**
 * One per payment module. Answers, for the sealed `paymentClientConfig`
 * query, the PUBLIC values the provider's browser SDK needs (Stripe
 * publishable key, PayPal client id, Mollie profile id): read from the
 * module's settings and the current test/live mode.
 *
 * Register as a service with the tag `payment_base.client_config_provider`.
 * Implement AllowListedClientConfigProviderInterface instead to also declare the
 * value names; the guard then refuses any other name.
 * The values are an allow-list: only what the browser may see, never a
 * secret. ClientConfigGuard refuses secret-looking keys/values anyway.
 *
 * @since 1.4.0
 */
interface ClientConfigProviderInterface
{
    /**
     * The `provider` argument of the query: 'stripe', 'paypal' or 'mollie'.
     */
    public function providerId(): string;

    /**
     * @return array<string, string|bool>
     */
    public function publicValues(): array;
}
