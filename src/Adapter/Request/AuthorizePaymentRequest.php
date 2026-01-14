<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Adapter\Request;

/**
 * Request for authorizing a payment without capturing it.
 *
 * Used in two-step payment flows where authorization and capture are separate:
 * Step 1: Authorize (reserve funds) - this request
 * Step 2: Capture (actually charge) - CaptureAuthorizationRequest
 *
 * Common use case: Reserve payment when order is placed, capture when shipped.
 *
 * Provider-agnostic - adapters translate to provider-specific formats.
 *
 * @since 1.0.0
 */
readonly class AuthorizePaymentRequest
{
    /**
     * @param float $amount Amount to authorize in major units
     * @param string $currency ISO 4217 currency code in uppercase
     * @param string $orderId Shop's internal order identifier
     * @param string $shopId Shop identifier
     * @param string $paymentMethod Generic payment method name
     * @param string|null $paymentMethodId Provider's payment method ID (for saved cards)
     * @param string|null $customerId Provider's customer ID
     * @param string|null $returnUrl Success redirect URL
     * @param string|null $cancelUrl Cancel redirect URL
     * @param array<string, mixed> $metadata Additional metadata
     * @param array<string, string>|null $billingAddress Billing address
     * @param array<string, string>|null $shippingAddress Shipping address
     */
    public function __construct(
        public float $amount,
        public string $currency,
        public string $orderId,
        public string $shopId,
        public string $paymentMethod,
        public ?string $paymentMethodId = null,
        public ?string $customerId = null,
        public ?string $returnUrl = null,
        public ?string $cancelUrl = null,
        public array $metadata = [],
        public ?array $billingAddress = null,
        public ?array $shippingAddress = null,
    ) {
    }
}
