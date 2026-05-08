<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter\Exception;

use RuntimeException;
use Throwable;

/**
 * Exception thrown when order operations fail.
 *
 * @since 1.0.0
 */
class ShopOrderException extends RuntimeException
{
    /**
     * @param string $message Error message
     * @param string $errorCode Shop-specific error code
     * @param array<string, mixed> $context Additional error context
     * @param int $code Exception code
     * @param Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message,
        private readonly string $errorCode = 'order_error',
        private readonly array $context = [],
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
