<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Service\Commit;

/**
 * "The provider says paid" - what a webhook handler or a headless return
 * mutation knows about a payment, in provider-neutral words.
 *
 * @since 3.0.0
 */
final readonly class PaymentConfirmation
{
    /**
     * @param string $providerName the provider as it names itself in contracts and contexts ('stripe', 'mollie', 'paypal')
     * @param string $authorizationId the PSP id that authorised the money (PaymentIntent, Mollie payment, PayPal authorization)
     * @param string $providerOrderId the PSP's order-level id (Checkout Session, Mollie order, PayPal order); may equal $authorizationId
     * @param bool $requiresCapture true for a manual-capture authorization: the order is not marked paid yet
     * @param string $source who is confirming: 'webhook', 'return', 'headless_return', ... (travels as `commitSource`)
     * @param array<string, mixed> $extraContext provider-specific keys the handler chain reads (e.g. `checkoutSessionId`)
     */
    public function __construct(
        public string $contractId,
        public string $providerName,
        public string $authorizationId,
        public string $providerOrderId,
        public float $amount,
        public string $currency,
        public bool $requiresCapture = false,
        public string $source = 'webhook',
        public array $extraContext = [],
    ) {
    }
}
