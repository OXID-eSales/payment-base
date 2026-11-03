<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\LateMoney;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * The one rule for money on a contract that can no longer take it: a terminal contract (cancelled, expired,
 * failed) or an attempt the contract replaced. Captured money is refunded, an authorization is released,
 * once, and the order is flagged when the provider refuses.
 *
 * @since 1.8.0
 */
interface LateMoneyResolverInterface
{
    /**
     * @param string $providerPaymentId the provider's payment that holds the money; the idempotency key is
     *                                  `late:<providerPaymentId>`, so every delivery for it is the same operation
     *
     * @throws \LogicException no gateway tagged for the contract's provider (wiring error)
     * @throws \OxidEsales\PaymentBase\Repository\StaleContractException the contract changed under the save; the
     *                                                                    provider's retry finds the money recorded
     */
    public function resolve(PaymentContractInterface $contract, string $providerPaymentId): LateMoneyOutcome;
}
