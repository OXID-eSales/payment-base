<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Repository;

use OxidEsales\PaymentBase\Contract\PaymentContractSummary;

/**
 * Read side for order lists: the summary of the contract behind an order.
 *
 * @since 1.4.0
 */
interface ContractSummaryQueryInterface
{
    /**
     * @return PaymentContractSummary|null null for an order without a contract (e.g. one placed through the Twig flow
     *         of a payment that is not contract-first)
     */
    public function findByOrderId(string $orderId): ?PaymentContractSummary;
}
