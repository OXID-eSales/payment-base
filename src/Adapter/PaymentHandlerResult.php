<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter;

/**
 * Result returned by payment handlers
 */
class PaymentHandlerResult
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly bool $success,
        private readonly ?string $contractId = null,
        private readonly ?string $clientSecret = null,
        private readonly ?string $errorMessage = null,
        private readonly ?string $errorCode = null,
        private readonly array $metadata = []
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getContractId(): ?string
    {
        return $this->contractId;
    }

    public function getClientSecret(): ?string
    {
        return $this->clientSecret;
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
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getMetadataValue(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function success(
        string $contractId,
        ?string $clientSecret = null,
        array $metadata = []
    ): self {
        return new self(
            success: true,
            contractId: $contractId,
            clientSecret: $clientSecret,
            metadata: $metadata
        );
    }

    public static function error(string $errorMessage, ?string $errorCode = null): self
    {
        return new self(
            success: false,
            errorMessage: $errorMessage,
            errorCode: $errorCode
        );
    }
}
