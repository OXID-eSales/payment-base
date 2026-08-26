<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Contract;

/**
 * Assigns a payment method to the running checkout on the customer's behalf.
 *
 * The user is passed as `mixed` on purpose: it is OXID's User model, a core
 * class with no interface, and payment-base does not type against concrete
 * shop classes.
 */
interface SinglePaymentAssignerInterface
{
    /**
     * @return bool true when the method was validated and assigned
     */
    public function assign(string $paymentId, mixed $user): bool;
}
