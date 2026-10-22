<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Repository;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Contract\PaymentContractSummary;

/**
 * @since 1.4.0
 */
final class ContractSummaryQuery implements ContractSummaryQueryInterface
{
    public function __construct(private readonly ContractRepositoryInterface $contracts)
    {
    }

    public function findByOrderId(string $orderId): ?PaymentContractSummary
    {
        $contract = $this->contracts->findByOrderId($orderId);
        if ($contract === null) {
            return null;
        }

        return new PaymentContractSummary(
            contractId: (string) $contract->getId(),
            state: $contract->getStateValue(),
            provider: (string) $contract->getProvider(),
            updatedAt: DateTimeImmutable::createFromInterface($contract->getUpdatedAt()),
        );
    }
}
