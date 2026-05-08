<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter\Request;

/**
 * Provider-agnostic request for creating a payment.
 *
 * @since 1.0.0
 */
readonly class CreatePaymentRequest
{
    /**
     * @param float $amount Payment amount
     * @param string $currency Currency code (ISO 4217)
     * @param string $orderId Order identifier
     * @param string $shopId Shop identifier
     * @param string $paymentMethod Payment method name
     * @param bool $directCapture Whether to capture immediately
     * @param string|null $paymentMethodId Stored payment method ID
     * @param string|null $customerId Customer identifier
     * @param string|null $returnUrl URL to return to after payment
     * @param string|null $cancelUrl URL to redirect to on cancellation
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
        public bool $directCapture = false,
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
