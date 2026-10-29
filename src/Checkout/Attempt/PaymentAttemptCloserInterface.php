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
 * One for each provider: closes the provider's payment page of an attempt the
 * customer leaves for another payment method (OPC-175).
 *
 * @since OPC-175
 */
interface PaymentAttemptCloserInterface
{
    /** The provider name the contract carries (PaymentContractInterface::getProvider()). */
    public function provider(): string;

    public function close(PaymentContractInterface $contract): AttemptClosure;
}
