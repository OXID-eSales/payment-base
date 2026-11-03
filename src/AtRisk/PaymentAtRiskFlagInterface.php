<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\AtRisk;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * Marks a contract and its order "payment at risk" and tells the shop owner once. It never cancels, refunds
 * or changes the contract: the merchant decides, then acknowledges.
 *
 * @since 1.8.0
 */
interface PaymentAtRiskFlagInterface
{
    /**
     * @param string $reference the provider's id of the event behind it (capture, dispute, payment); empty when none
     *
     * @return bool true when the flag is new
     */
    public function raise(PaymentContractInterface $contract, AtRiskReason $reason, string $reference = ''): bool;

    /**
     * @return bool true when an open flag was acknowledged
     */
    public function acknowledge(string $contractId, AtRiskReason $reason, string $reference = ''): bool;
}
