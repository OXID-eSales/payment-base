<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Service\Exception;

use RuntimeException;
use Throwable;

/**
 * Exception thrown when stock release fails.
 *
 * Sprint 2: Used by StockService when stock cannot be released.
 * Per Q&A decision, this enforces strict consistency - if stock
 * cannot be released, the operation fails.
 *
 * @since 1.0.0
 */
class StockReleaseException extends RuntimeException
{
    /**
     * @param string $contractId Contract ID for which release failed
     * @param string $reason Human-readable reason for failure
     * @param Throwable|null $previous Previous exception if any
     */
    public function __construct(
        private readonly string $contractId,
        string $reason,
        ?Throwable $previous = null
    ) {
        parent::__construct(
            sprintf('Failed to release stock for contract %s: %s', $contractId, $reason),
            0,
            $previous
        );
    }

    public function getContractId(): string
    {
        return $this->contractId;
    }
}
