<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Adapter\Response;

/**
 * Normalized response from capturing a payment.
 *
 * Provider-agnostic response for payment capture operations.
 *
 * @since 1.0.0
 */
readonly class CaptureResponse
{
    /**
     * @param string $providerPaymentId Provider's payment ID
     * @param string $captureId Provider's capture ID (may be same as payment ID)
     * @param float $amountCaptured Amount actually captured in major units
     * @param string $currency ISO 4217 currency code
     * @param string $status Capture status ('succeeded', 'pending', 'failed')
     * @param \DateTimeInterface $capturedAt Timestamp when capture occurred
     * @param array<string, mixed> $providerData Raw provider-specific data
     * @param array<string, mixed> $metadata Metadata
     */
    public function __construct(
        public string $providerPaymentId,
        public string $captureId,
        public float $amountCaptured,
        public string $currency,
        public string $status,
        public \DateTimeInterface $capturedAt,
        public array $providerData = [],
        public array $metadata = [],
    ) {
    }
}
