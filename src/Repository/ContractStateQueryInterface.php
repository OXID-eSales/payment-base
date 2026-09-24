<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Repository;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * The one read a reconciliation needs and the contract repository's main interface does not offer:
 * "every contract of this provider in this state" (MOL-17: contracts stuck at `committed` whose PSP
 * payment is paid). Kept apart from {@see ContractRepositoryInterface} so consumers that only sweep
 * do not depend on the whole repository.
 */
interface ContractStateQueryInterface
{
    /**
     * @return list<PaymentContractInterface> oldest first
     */
    public function findByStateAndProvider(string $state, string $provider, ?int $limit = null): array;
}
