<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Service\Result;

use DateTimeImmutable;

/**
 * Result of a payment capture operation.
 *
 * Sprint 3: Immutable value object returned by AbstractPaymentCaptureService.
 * Sprint 25: Added success/failure factory methods for unified DTO pattern.
 *
 * Supports two usage patterns:
 * 1. Simple constructor (for abstract services that throw exceptions on failure)
 * 2. Factory methods (for services that return failure results)
 *
 * @since 1.0.0
 */
final readonly class CaptureResult
{
    /**
     * @param bool $successful Whether the capture was successful
     * @param string|null $captureId Provider's capture/charge ID
     * @param float|null $amountCaptured Amount that was captured
     * @param string|null $currency Currency code (EUR, USD, etc.)
     * @param DateTimeImmutable|null $capturedAt When the capture occurred
     * @param string|null $errorMessage Error message if failed
     * @param string|null $errorCode Error code if failed
     * @param array<string, mixed> $providerData Additional provider-specific data
     */
    private function __construct(
        private bool $successful,
        private ?string $captureId,
        private ?float $amountCaptured,
        private ?string $currency,
        private ?DateTimeImmutable $capturedAt,
        private ?string $errorMessage,
        private ?string $errorCode,
        private array $providerData = []
    ) {
    }

    /**
     * Create a successful capture result (simple form).
     *
     * Used by AbstractPaymentCaptureService for contract-based captures.
     */
    public static function create(
        string $captureId,
        float $amountCaptured,
        string $currency,
        DateTimeImmutable $capturedAt,
        array $providerData = []
    ): self {
        return new self(
            successful: true,
            captureId: $captureId,
            amountCaptured: $amountCaptured,
            currency: $currency,
            capturedAt: $capturedAt,
            errorMessage: null,
            errorCode: null,
            providerData: $providerData
        );
    }

    /**
     * Create a successful capture result (full form).
     *
     * Used by direct API services for captures.
     */
    public static function success(
        string $captureId,
        float $amountCaptured,
        string $currency,
        DateTimeImmutable $capturedAt
    ): self {
        return new self(
            successful: true,
            captureId: $captureId,
            amountCaptured: $amountCaptured,
            currency: $currency,
            capturedAt: $capturedAt,
            errorMessage: null,
            errorCode: null,
            providerData: []
        );
    }

    /**
     * Create a failed capture result.
     */
    public static function failure(string $message, ?string $code = null): self
    {
        return new self(
            successful: false,
            captureId: null,
            amountCaptured: null,
            currency: null,
            capturedAt: null,
            errorMessage: $message,
            errorCode: $code,
            providerData: []
        );
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    public function getCaptureId(): ?string
    {
        return $this->captureId;
    }

    public function getAmountCaptured(): ?float
    {
        return $this->amountCaptured;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function getCapturedAt(): ?DateTimeImmutable
    {
        return $this->capturedAt;
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
