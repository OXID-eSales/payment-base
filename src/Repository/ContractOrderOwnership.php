<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Repository;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * May this contract still change its order?
 *
 * OPC-175: when the customer changes the payment method, the order is kept
 * and a new contract of the new provider takes it over; the old contract is
 * cancelled. A PSP event for the old contract (cancel, fail, expiry) must not
 * end the order the new contract is being paid with. Only the active contract
 * of the order (ContractRepositoryInterface::findByOrderId()) owns it.
 *
 * When the lookup finds nothing, the contract is treated as the owner: that
 * is the behaviour from before, and it cannot hit a replaced contract.
 *
 * @since OPC-175
 */
class ContractOrderOwnership
{
    public function __construct(private readonly ContractRepositoryInterface $contractRepository)
    {
    }

    public function ownsItsOrder(PaymentContractInterface $contract): bool
    {
        $orderId = $contract->getOrderId();
        if ($orderId === null || $orderId === '') {
            return false;
        }

        $active = $this->contractRepository->findByOrderId($orderId);

        return $active === null || $active->getId() === $contract->getId();
    }
}
