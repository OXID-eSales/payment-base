<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Service\Result;

/**
 * Result of a payment cancellation/void operation.
 *
 * Sprint 25: Provider-agnostic cancellation result.
 *
 * Used when cancelling/voiding an authorized payment that has not been captured.
 *
 * @since 1.0.0
 */
final readonly class CancellationResult
{
    /**
     * @param bool $successful Whether the cancellation was successful
     * @param string|null $authorizationId Provider's authorization/payment ID that was cancelled
     * @param string|null $status Cancellation status (cancelled, voided, etc.)
     * @param string|null $errorMessage Error message if failed
     * @param string|null $errorCode Error code if failed
     */
    private function __construct(
        private bool $successful,
        private ?string $authorizationId,
        private ?string $status,
        private ?string $errorMessage,
        private ?string $errorCode
    ) {
    }

    /**
     * Create a successful cancellation result.
     */
    public static function success(string $authorizationId, string $status = 'cancelled'): self
    {
        return new self(
            successful: true,
            authorizationId: $authorizationId,
            status: $status,
            errorMessage: null,
            errorCode: null
        );
    }

    /**
     * Create a failed cancellation result.
     */
    public static function failure(string $message, ?string $code = null): self
    {
        return new self(
            successful: false,
            authorizationId: null,
            status: null,
            errorMessage: $message,
            errorCode: $code
        );
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    /**
     * Get the authorization/payment ID that was cancelled.
     *
     * For Stripe: PaymentIntent ID
     * For PayPal: Authorization ID
     */
    public function getAuthorizationId(): ?string
    {
        return $this->authorizationId;
    }

    /**
     * Alias for getAuthorizationId() for Stripe compatibility.
     */
    public function getPaymentIntentId(): ?string
    {
        return $this->authorizationId;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }
}
