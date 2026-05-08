<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Service\Exception;

use RuntimeException;
use Throwable;

/**
 * Exception thrown when payment refund fails.
 *
 * Sprint 3: Used by AbstractPaymentRefundService and its implementations.
 *
 * @since 1.0.0
 */
class RefundFailedException extends RuntimeException
{
    public function __construct(
        private readonly string $contractId,
        string $reason,
        ?Throwable $previous = null
    ) {
        parent::__construct(
            sprintf('Refund failed for contract %s: %s', $contractId, $reason),
            0,
            $previous
        );
    }

    public function getContractId(): string
    {
        return $this->contractId;
    }
}
