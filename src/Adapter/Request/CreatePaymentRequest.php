<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Adapter\Request;

/**
 * Provider-agnostic request for creating a payment.
 *
 * @since 1.0.0
 */
readonly class CreatePaymentRequest
{
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
