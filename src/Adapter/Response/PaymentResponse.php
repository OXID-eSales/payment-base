<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Adapter\Response;

/**
 * Normalized response from creating a payment.
 *
 * Provider-agnostic response object that contains standardized
 * payment data regardless of the payment provider used.
 *
 * @since 1.0.0
 */
readonly class PaymentResponse
{
    /**
     * @param string $providerPaymentId Provider's payment ID (e.g., Stripe PaymentIntent ID)
     * @param string $status Normalized status ('pending', 'authorized', 'captured', 'failed', 'cancelled')
     * @param float $amount Payment amount in major units
     * @param string $currency ISO 4217 currency code
     * @param bool $requiresAction Whether additional customer action is required (e.g., 3DS)
     * @param string|null $clientSecret Client secret for frontend confirmation (if applicable)
     * @param string|null $redirectUrl URL to redirect customer for additional authentication
     * @param array<string, mixed> $providerData Raw provider-specific data for debugging
     * @param array<string, mixed> $metadata Metadata associated with payment
     */
    public function __construct(
        public string $providerPaymentId,
        public string $status,
        public float $amount,
        public string $currency,
        public bool $requiresAction = false,
        public ?string $clientSecret = null,
        public ?string $redirectUrl = null,
        public array $providerData = [],
        public array $metadata = [],
    ) {
    }
}
