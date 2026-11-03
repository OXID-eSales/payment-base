<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Payment;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * The provider-agnostic rules of a capture (and of voiding the authorisation it would have taken).
 *
 * @since 1.7.0
 */
interface CapturePolicyInterface
{
    /** What may still be captured: nothing unless the payment is an open authorisation. */
    public function remaining(PaymentContractInterface $contract): float;

    public function decide(PaymentContractInterface $contract, float $amount, string $currency): PolicyDecision;
}
