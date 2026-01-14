<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Service\Result;

/**
 * Immutable value object representing order confirmation result.
 *
 * @since 1.0.0
 */
readonly class OrderConfirmationResult
{
    public const STATE_PENDING = 'PENDING';
    public const STATE_COMMITTED = 'COMMITTED';
    public const STATE_FULFILLED = 'FULFILLED';
    public const STATE_FAILED = 'FAILED';

    private function __construct(
        private bool $success,
        private string $contractState,
        private ?string $errorMessage = null
    ) {
    }

    /**
     * Creates a successful confirmation result.
     */
    public static function success(string $contractState): self
    {
        return new self(
            success: true,
            contractState: $contractState
        );
    }

    /**
     * Creates a failed confirmation result.
     */
    public static function failure(string $errorMessage, string $contractState = self::STATE_FAILED): self
    {
        return new self(
            success: false,
            contractState: $contractState,
            errorMessage: $errorMessage
        );
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getContractState(): string
    {
        return $this->contractState;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * Returns true if contract is waiting for payment webhook confirmation.
     */
    public function isAwaitingPaymentConfirmation(): bool
    {
        return $this->contractState === self::STATE_COMMITTED;
    }

    /**
     * Returns true if contract is fully completed.
     */
    public function isFullyCompleted(): bool
    {
        return $this->contractState === self::STATE_FULFILLED;
    }
}
