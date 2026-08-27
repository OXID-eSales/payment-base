<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Contract;

/**
 * Assigns a delivery set to the running checkout on the customer's behalf.
 *
 * Takes no user: unlike a payment method, a delivery set needs no per-user
 * validation here. The id handed over came out of OXID's own filtered active-set
 * list — which is already resolved for this user, country and basket — one
 * statement earlier, so validation is by construction rather than by a second
 * call.
 */
interface SingleShippingAssignerInterface
{
    /**
     * @return bool true when the delivery set was assigned
     */
    public function assign(string $shipSetId): bool;
}
