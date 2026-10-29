<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Attempt;

use OxidEsales\PaymentBase\Contract\AttemptClosure;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * OPC-175 — closes the provider's payment page of the attempt a contract leaves.
 *
 * @since OPC-175
 */
interface PaymentAttemptClosersInterface
{
    public function close(PaymentContractInterface $contract): AttemptClosure;
}
