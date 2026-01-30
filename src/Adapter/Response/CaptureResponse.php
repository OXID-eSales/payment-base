<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Adapter\Response;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Normalized response from capturing a payment.
 *
 * Provider-agnostic response for payment capture operations.
 * Sprint 31: Added success/failure factory methods and error handling.
 *
 * @since 1.0.0
 */
readonly class CaptureResponse
{
    /**
     * @param bool $successful Whether the capture was successful
     * @param string|null $providerPaymentId Provider's payment ID
     * @param string|null $captureId Provider's capture ID (may be same as payment ID)
     * @param float|null $amountCaptured Amount actually captured in major units
     * @param string|null $currency ISO 4217 currency code
     * @param string|null $status Capture status ('succeeded', 'pending', 'failed')
     * @param DateTimeInterface|null $capturedAt Timestamp when capture occurred
     * @param string|null $errorMessage Error message if failed
     * @param string|null $errorCode Error code if failed
     * @param array<string, mixed> $providerData Raw provider-specific data
     * @param array<string, mixed> $metadata Metadata
     */
    private function __construct(
        public bool $successful,
        public ?string $providerPaymentId,
        public ?string $captureId,
        public ?float $amountCaptured,
        public ?string $currency,
        public ?string $status,
        public ?DateTimeInterface $capturedAt,
        public ?string $errorMessage,
        public ?string $errorCode,
        public array $providerData,
        public array $metadata,
    ) {
    }

    /**
     * Create a successful capture response.
     *
     * @param array<string, mixed> $providerData
     * @param array<string, mixed> $metadata
     */
    public static function success(
        string $providerPaymentId,
        string $captureId,
        float $amountCaptured,
        string $currency,
        string $status,
        DateTimeInterface $capturedAt,
        array $providerData = [],
        array $metadata = []
    ): self {
        return new self(
            successful: true,
            providerPaymentId: $providerPaymentId,
            captureId: $captureId,
            amountCaptured: $amountCaptured,
            currency: $currency,
            status: $status,
            capturedAt: $capturedAt,
            errorMessage: null,
            errorCode: null,
            providerData: $providerData,
            metadata: $metadata,
        );
    }

    /**
     * Create a failed capture response.
     */
    public static function failure(string $errorMessage, ?string $errorCode = null): self
    {
        return new self(
            successful: false,
            providerPaymentId: null,
            captureId: null,
            amountCaptured: null,
            currency: null,
            status: 'failed',
            capturedAt: null,
            errorMessage: $errorMessage,
            errorCode: $errorCode,
            providerData: [],
            metadata: [],
        );
    }

    /**
     * Check if the capture was successful.
     */
    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    /**
     * Get error message if capture failed.
     */
    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * Get error code if capture failed.
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }
}
