<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\LateMoney;

/**
 * The provider's answer to "what does this payment hold?".
 *
 * @since 1.8.0
 */
final readonly class ProviderPaymentStatus
{
    /**
     * @param string $providerPaymentId the id the money sits under at the provider (the id a refund or a
     *                                  commit confirmation names: PaymentIntent, Mollie payment, PayPal capture)
     */
    public function __construct(
        public ProviderPaymentState $state,
        public string $providerPaymentId,
        public float $amount,
        public string $currency,
    ) {
    }
}
