<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Payment;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * The provider-agnostic rules of a refund.
 *
 * @since 1.7.0
 */
interface RefundPolicyInterface
{
    /** What may still be refunded: what was captured less what was refunded. */
    public function remaining(PaymentContractInterface $contract): float;

    public function decide(PaymentContractInterface $contract, float $amount, string $currency): PolicyDecision;
}
