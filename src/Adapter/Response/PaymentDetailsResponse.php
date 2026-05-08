<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter\Response;

/**
 * Normalized response for payment details/status lookup.
 *
 * Provides current state and history of a payment.
 *
 * @since 1.0.0
 */
readonly class PaymentDetailsResponse
{
    /**
     * @param string $providerPaymentId Provider's payment ID
     * @param string $status Current payment status
     * @param float $amount Payment amount in major units
     * @param string $currency ISO 4217 currency code
     * @param float $amountCaptured Amount captured so far
     * @param float $amountRefunded Amount refunded so far
     * @param bool $isCaptured Whether payment has been captured
     * @param bool $isRefunded Whether payment has been refunded
     * @param bool $isCancelled Whether payment has been cancelled
     * @param \DateTimeInterface $createdAt Payment creation timestamp
     * @param \DateTimeInterface|null $capturedAt Capture timestamp
     * @param \DateTimeInterface|null $refundedAt Refund timestamp
     * @param array<string, mixed> $providerData Raw provider-specific data
     * @param array<string, mixed> $metadata Metadata
     */
    public function __construct(
        public string $providerPaymentId,
        public string $status,
        public float $amount,
        public string $currency,
        public float $amountCaptured,
        public float $amountRefunded,
        public bool $isCaptured,
        public bool $isRefunded,
        public bool $isCancelled,
        public \DateTimeInterface $createdAt,
        public ?\DateTimeInterface $capturedAt = null,
        public ?\DateTimeInterface $refundedAt = null,
        public array $providerData = [],
        public array $metadata = [],
    ) {
    }
}
