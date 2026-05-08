<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter\Response;

use DateTimeInterface;

/**
 * Normalized response from refunding a payment.
 *
 * Provider-agnostic response for refund operations.
 * Sprint 31: Added success/failure factory methods and error handling.
 *
 * @since 1.0.0
 */
readonly class RefundResponse
{
    /**
     * @param bool $successful Whether the refund was successful
     * @param string|null $providerPaymentId Provider's payment ID
     * @param string|null $refundId Provider's refund ID
     * @param float|null $amountRefunded Amount refunded in major units
     * @param string|null $currency ISO 4217 currency code
     * @param string|null $status Refund status ('succeeded', 'pending', 'failed', 'cancelled')
     * @param DateTimeInterface|null $refundedAt Timestamp when refund occurred
     * @param string|null $reason Refund reason
     * @param string|null $errorMessage Error message if failed
     * @param string|null $errorCode Error code if failed
     * @param array<string, mixed> $providerData Raw provider-specific data
     * @param array<string, mixed> $metadata Metadata
     */
    private function __construct(
        public bool $successful,
        public ?string $providerPaymentId,
        public ?string $refundId,
        public ?float $amountRefunded,
        public ?string $currency,
        public ?string $status,
        public ?DateTimeInterface $refundedAt,
        public ?string $reason,
        public ?string $errorMessage,
        public ?string $errorCode,
        public array $providerData,
        public array $metadata,
    ) {
    }

    /**
     * Create a successful refund response.
     *
     * @param array<string, mixed> $providerData
     * @param array<string, mixed> $metadata
     */
    public static function success(
        string $providerPaymentId,
        string $refundId,
        float $amountRefunded,
        string $currency,
        string $status,
        DateTimeInterface $refundedAt,
        ?string $reason = null,
        array $providerData = [],
        array $metadata = []
    ): self {
        return new self(
            successful: true,
            providerPaymentId: $providerPaymentId,
            refundId: $refundId,
            amountRefunded: $amountRefunded,
            currency: $currency,
            status: $status,
            refundedAt: $refundedAt,
            reason: $reason,
            errorMessage: null,
            errorCode: null,
            providerData: $providerData,
            metadata: $metadata,
        );
    }

    /**
     * Create a failed refund response.
     */
    public static function failure(string $errorMessage, ?string $errorCode = null): self
    {
        return new self(
            successful: false,
            providerPaymentId: null,
            refundId: null,
            amountRefunded: null,
            currency: null,
            status: 'failed',
            refundedAt: null,
            reason: null,
            errorMessage: $errorMessage,
            errorCode: $errorCode,
            providerData: [],
            metadata: [],
        );
    }

    /**
     * Check if the refund was successful.
     */
    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    /**
     * Get error message if refund failed.
     */
    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * Get error code if refund failed.
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }
}
