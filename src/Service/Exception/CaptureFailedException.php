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
 * Exception thrown when payment capture fails.
 *
 * Sprint 3: Used by AbstractPaymentCaptureService and its implementations.
 *
 * @since 1.0.0
 */
class CaptureFailedException extends RuntimeException
{
    public function __construct(
        private readonly string $contractId,
        string $reason,
        ?Throwable $previous = null
    ) {
        parent::__construct(
            sprintf('Capture failed for contract %s: %s', $contractId, $reason),
            0,
            $previous
        );
    }

    public function getContractId(): string
    {
        return $this->contractId;
    }
}
