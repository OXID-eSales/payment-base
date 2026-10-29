<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Repository;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * OPC-175 / S2g — the contract that replaced a provider attempt, by that
 * attempt's provider order id. A provider event of a replaced attempt matches
 * no contract by its current provider order id; money that arrives on it is
 * refunded, and the refund is recorded on this contract.
 *
 * @since OPC-175
 */
interface ReplacedAttemptFinderInterface
{
    public function findByReplacedAttempt(string $providerOrderId): ?PaymentContractInterface;
}
