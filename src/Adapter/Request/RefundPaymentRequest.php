<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter\Request;

/**
 * Request for refunding a captured payment.
 *
 * Used to return funds to the customer after payment capture.
 *
 * Supports:
 * - Full refund (amount = null)
 * - Partial refund (amount < captured amount)
 *
 * Provider-agnostic - adapters translate to provider-specific formats.
 *
 * @since 1.0.0
 */
readonly class RefundPaymentRequest
{
    /**
     * @param string $providerPaymentId Provider's payment ID
     * @param float|null $amount Amount to refund in major units (null = full refund)
     * @param string|null $reason Refund reason ('duplicate', 'fraudulent', 'requested_by_customer')
     * @param array<string, mixed> $metadata Additional metadata
     */
    public function __construct(
        public string $providerPaymentId,
        public ?float $amount = null,
        public ?string $reason = null,
        public array $metadata = [],
    ) {
    }
}
