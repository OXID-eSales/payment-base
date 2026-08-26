<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Contract;

/**
 * Answers one question: is there exactly one payment method the shop can
 * assign without asking the customer anything?
 *
 * Consumers pass the candidates derived from the payment list OXID has already
 * filtered for the current user, delivery set and basket price. The answer is
 * the payment id to assign, or null when the customer has to choose.
 */
interface SinglePaymentResolverInterface
{
    /**
     * @param array<array-key, PaymentCandidate> $candidates
     */
    public function resolve(array $candidates): ?string;
}
