<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Adapter\Response;

use DateTimeInterface;

/**
 * Normalized response from cancelling/voiding a payment authorization.
 *
 * Provider-agnostic response for cancellation/void operations.
 * Sprint 31: Replaces VoidResponse with consistent success/failure pattern.
 *
 * @since 1.0.0
 */
readonly class CancellationResponse
{
    /**
     * @param bool $successful Whether the cancellation was successful
     * @param string|null $providerPaymentId Provider's payment ID
     * @param string|null $authorizationId Provider's authorization ID that was cancelled
     * @param string|null $status Cancellation status ('cancelled', 'voided', 'failed')
     * @param DateTimeInterface|null $cancelledAt Timestamp when cancellation occurred
     * @param string|null $reason Cancellation reason
     * @param string|null $errorMessage Error message if failed
     * @param string|null $errorCode Error code if failed
     * @param array<string, mixed> $providerData Raw provider-specific data
     * @param array<string, mixed> $metadata Metadata
     */
    private function __construct(
        public bool $successful,
        public ?string $providerPaymentId,
        public ?string $authorizationId,
        public ?string $status,
        public ?DateTimeInterface $cancelledAt,
        public ?string $reason,
        public ?string $errorMessage,
        public ?string $errorCode,
        public array $providerData,
        public array $metadata,
    ) {
    }

    /**
     * Create a successful cancellation response.
     *
     * @param array<string, mixed> $providerData
     * @param array<string, mixed> $metadata
     */
    public static function success(
        string $providerPaymentId,
        string $authorizationId,
        string $status,
        DateTimeInterface $cancelledAt,
        ?string $reason = null,
        array $providerData = [],
        array $metadata = []
    ): self {
        return new self(
            successful: true,
            providerPaymentId: $providerPaymentId,
            authorizationId: $authorizationId,
            status: $status,
            cancelledAt: $cancelledAt,
            reason: $reason,
            errorMessage: null,
            errorCode: null,
            providerData: $providerData,
            metadata: $metadata,
        );
    }

    /**
     * Create a failed cancellation response.
     */
    public static function failure(string $errorMessage, ?string $errorCode = null): self
    {
        return new self(
            successful: false,
            providerPaymentId: null,
            authorizationId: null,
            status: 'failed',
            cancelledAt: null,
            reason: null,
            errorMessage: $errorMessage,
            errorCode: $errorCode,
            providerData: [],
            metadata: [],
        );
    }

    /**
     * Check if the cancellation was successful.
     */
    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    /**
     * Alias for getAuthorizationId() for Stripe compatibility.
     */
    public function getPaymentIntentId(): ?string
    {
        return $this->authorizationId;
    }

    /**
     * Get error message if cancellation failed.
     */
    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * Get error code if cancellation failed.
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }
}
