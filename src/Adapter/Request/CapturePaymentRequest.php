<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter\Request;

/**
 * Request for capturing an authorized payment.
 *
 * Used in two-step payment flows:
 * 1. Authorize payment (reserve funds)
 * 2. Capture payment (actually charge)
 *
 * Supports:
 * - Full capture (amount = null)
 * - Partial capture (amount < authorized amount)
 *
 * Provider-agnostic - adapters translate to provider-specific formats.
 *
 * @since 1.0.0
 */
readonly class CapturePaymentRequest
{
    /**
     * @param string $providerPaymentId Provider's payment/authorization ID (e.g., Stripe PaymentIntent ID)
     * @param float|null $amount Amount to capture in major units (null = full capture)
     * @param array<string, mixed> $metadata Additional metadata to store with capture
     */
    public function __construct(
        public string $providerPaymentId,
        public ?float $amount = null,
        public array $metadata = [],
    ) {
    }
}
