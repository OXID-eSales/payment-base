<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Adapter\Exception;

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

    /**
     * Check if this is a network/timeout error.
     *
     * @return bool True if network-related error
     */
    public function isNetworkError(): bool
    {
        return in_array($this->errorCode, ['timeout', 'connection_error', 'network_error'], true);
    }

    /**
     * Check if this is an authentication error.
     *
     * @return bool True if authentication failed
     */
    public function isAuthenticationError(): bool
    {
        return in_array($this->errorCode, ['invalid_api_key', 'authentication_required', 'unauthorized'], true);
    }

    /**
     * Check if this error is retryable.
     *
     * @return bool True if operation can be retried
     */
    public function isRetryable(): bool
    {
        return $this->isNetworkError()
            || in_array($this->errorCode, ['rate_limit_error', 'idempotency_error'], true);
    }
}
