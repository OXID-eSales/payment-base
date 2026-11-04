<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter\Exception;

/**
 * Exception thrown by payment adapters on provider errors.
 *
 * Wraps provider-specific exceptions into a unified exception type.
 *
 * @since 1.0.0
 */
class PaymentAdapterException extends \RuntimeException
{
    /**
     * @param string $providerName Payment provider name (e.g., 'stripe', 'paypal')
     * @param string $errorCode Provider-specific error code
     * @param array<string, mixed> $context Additional error context
     */
    public function __construct(
        private readonly string $providerName,
        private readonly string $errorCode,
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly array $context = []
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Get the payment provider name.
     *
     * @return string Provider name
     */
    public function getProviderName(): string
    {
        return $this->providerName;
    }

    /**
     * Get the provider-specific error code.
     *
     * @return string Error code
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * Get additional error context.
     *
     * @return array<string, mixed> Error context
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
