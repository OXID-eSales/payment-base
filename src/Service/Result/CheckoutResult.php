<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Service\Result;

/**
 * Immutable value object representing checkout process result.
 *
 * @since 1.0.0
 */
readonly class CheckoutResult
{
    private function __construct(
        private bool $success,
        private ?string $contractId = null,
        private ?string $errorMessage = null,
        private ?string $errorCode = null
    ) {
    }

    /**
     * Creates a successful result.
     */
    public static function success(string $contractId): self
    {
        return new self(
            success: true,
            contractId: $contractId
        );
    }

    /**
     * Creates a failure result.
     */
    public static function failure(string $errorMessage, ?string $errorCode = null): self
    {
        return new self(
            success: false,
            errorMessage: $errorMessage,
            errorCode: $errorCode
        );
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getContractId(): ?string
    {
        return $this->contractId;
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
