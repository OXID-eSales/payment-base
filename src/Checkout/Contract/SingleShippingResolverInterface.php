<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Contract;

/**
 * Answers one question: is there exactly one delivery set the shop can assign
 * without asking the customer anything?
 *
 * Consumers pass the candidates derived from the delivery-set list OXID has
 * already filtered for the current user, country and basket. The answer is the
 * delivery-set id to assign, or null when the customer has to choose.
 */
interface SingleShippingResolverInterface
{
    /**
     * @param array<array-key, ShippingCandidate> $candidates
     */
    public function resolve(array $candidates): ?string;
}
