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
     * @param string|null $currency ISO-4217 currency code of the payment (e.g. 'JPY', 'EUR').
     *                              Used by adapters to convert amounts to the correct minor-unit
     *                              precision (zero-decimal currencies like JPY need no × 100).
     *                              Null/empty falls back to 2-decimal behaviour (safe for EUR).
     *                              Sprint 114.10a (§6.2): additive — existing callers unchanged.
     */
    public function __construct(
        public string $providerPaymentId,
        public ?float $amount = null,
        public ?string $reason = null,
        public array $metadata = [],
        public ?string $currency = null,
    ) {
    }
}
