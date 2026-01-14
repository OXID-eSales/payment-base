<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Adapter\Response;

/**
 * Normalized response from refunding a payment.
 *
 * Provider-agnostic response for refund operations.
 *
 * @since 1.0.0
 */
readonly class RefundResponse
{
    /**
     * @param string $providerPaymentId Provider's payment ID
     * @param string $refundId Provider's refund ID
     * @param float $amountRefunded Amount refunded in major units
     * @param string $currency ISO 4217 currency code
     * @param string $status Refund status ('succeeded', 'pending', 'failed', 'cancelled')
     * @param \DateTimeInterface $refundedAt Timestamp when refund occurred
     * @param string|null $reason Refund reason
     * @param array<string, mixed> $providerData Raw provider-specific data
     * @param array<string, mixed> $metadata Metadata
     */
    public function __construct(
        public string $providerPaymentId,
        public string $refundId,
        public float $amountRefunded,
        public string $currency,
        public string $status,
        public \DateTimeInterface $refundedAt,
        public ?string $reason = null,
        public array $providerData = [],
        public array $metadata = [],
    ) {
    }
}
