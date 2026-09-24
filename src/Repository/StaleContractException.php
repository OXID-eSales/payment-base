<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Repository;

use RuntimeException;

/**
 * Thrown by {@see ContractRepositoryInterface::save()} when the row was changed by somebody else since
 * this copy of the contract was loaded (MOL-17). The shopper's return leg and the PSP webhook race on the
 * same contract; the loser must reload and decide, never overwrite.
 */
final class StaleContractException extends RuntimeException
{
    public function __construct(
        public readonly string $contractId,
        public readonly int $expectedVersion,
        public readonly int $currentVersion,
    ) {
        parent::__construct(sprintf(
            'Contract %s changed since it was loaded (expected version %d, row is at %d)',
            $contractId,
            $expectedVersion,
            $currentVersion,
        ));
    }
}
