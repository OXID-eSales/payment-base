<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Attempt;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * MOL-39: the right to create the order of a contract. Requests that loaded the same copy of a contract
 * (also from different sessions) ask for it; one gets it.
 *
 * @since sprint 143 (AW)
 */
interface OrderCreationClaimInterface
{
    /**
     * @return bool false when another request holds the claim for this copy of the contract
     */
    public function claim(PaymentContractInterface $contract): bool;

    /**
     * Gives the claim back: the order creation failed and the next attempt may create the order.
     */
    public function release(PaymentContractInterface $contract): void;
}
