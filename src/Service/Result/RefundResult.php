<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Service\Result;

use DateTimeImmutable;

/**
 * Result of a payment refund operation.
 *
 * Sprint 3: Immutable value object returned by AbstractPaymentRefundService.
 * Sprint 25: Added success/failure factory methods for unified DTO pattern.
 *
 * Supports two usage patterns:
 * 1. Simple constructor (for abstract services that throw exceptions on failure)
 * 2. Factory methods (for services that return failure results)
 *
 * @since 1.0.0
 */
final readonly class RefundResult
{
    /**
     * @param bool $successful Whether the refund was successful
     * @param string|null $refundId Provider's refund ID
     * @param float|null $amountRefunded Amount that was refunded
     * @param string|null $currency Currency code (EUR, USD, etc.)
     * @param float|null $totalRefunded Total amount refunded for this contract
     * @param float|null $availableForRefund Remaining amount that can still be refunded
     * @param DateTimeImmutable|null $refundedAt When the refund occurred
     * @param string|null $status Refund status (succeeded, pending, failed)
     * @param string|null $errorMessage Error message if failed
     * @param string|null $errorCode Error code if failed
     * @param array<string, mixed> $providerData Additional provider-specific data
     */
    private function __construct(
        private bool $successful,
        private ?string $refundId,
        private ?float $amountRefunded,
        private ?string $currency,
        private ?float $totalRefunded,
        private ?float $availableForRefund,
        private ?DateTimeImmutable $refundedAt,
        private ?string $status,
        private ?string $errorMessage,
        private ?string $errorCode,
        private array $providerData = []
    ) {
    }

    /**
     * Create a successful refund result (simple form).
     *
     * Used by AbstractPaymentRefundService for contract-based refunds.
     *
     * @param array<string, mixed> $providerData
     */
    public static function create(
        string $refundId,
        float $amountRefunded,
        string $currency,
        float $totalRefunded,
        float $availableForRefund,
        DateTimeImmutable $refundedAt,
        array $providerData = []
    ): self {
        return new self(
            successful: true,
            refundId: $refundId,
            amountRefunded: $amountRefunded,
            currency: $currency,
            totalRefunded: $totalRefunded,
            availableForRefund: $availableForRefund,
            refundedAt: $refundedAt,
            status: 'succeeded',
            errorMessage: null,
            errorCode: null,
            providerData: $providerData
        );
    }

    /**
     * Create a successful refund result (full form with status).
     *
     * Used by direct API services for refunds with status tracking.
     */
    public static function success(
        string $refundId,
        int $amountCents,
        string $currency,
        string $status = 'succeeded'
    ): self {
        return new self(
            successful: true,
            refundId: $refundId,
            amountRefunded: $amountCents / 100,
            currency: $currency,
            totalRefunded: null,
            availableForRefund: null,
            refundedAt: new DateTimeImmutable(),
            status: $status,
            errorMessage: null,
            errorCode: null,
            providerData: []
        );
    }

    /**
     * Create a failed refund result.
     */
    public static function failure(string $errorMessage, ?string $errorCode = null): self
    {
        return new self(
            successful: false,
            refundId: null,
            amountRefunded: null,
            currency: null,
            totalRefunded: null,
            availableForRefund: null,
            refundedAt: null,
            status: 'failed',
            errorMessage: $errorMessage,
            errorCode: $errorCode,
            providerData: []
        );
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    public function getRefundId(): ?string
    {
        return $this->refundId;
    }

    public function getAmountRefunded(): ?float
    {
        return $this->amountRefunded;
    }

    /**
     * Get the refunded amount in cents.
     */
    public function getRefundedAmountCents(): ?int
    {
        if ($this->amountRefunded === null) {
            return null;
        }
        return (int) ($this->amountRefunded * 100);
    }

    /**
     * Alias for getAmountRefunded() for backward compatibility.
     */
    public function getRefundedAmount(): ?float
    {
        return $this->amountRefunded;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function getTotalRefunded(): ?float
    {
        return $this->totalRefunded;
    }

    public function getAvailableForRefund(): ?float
    {
        return $this->availableForRefund;
    }

    public function getRefundedAt(): ?DateTimeImmutable
    {
        return $this->refundedAt;
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

    /**
     * @return array<string, mixed>
     */
    public function getProviderData(): array
    {
        return $this->providerData;
    }
}
