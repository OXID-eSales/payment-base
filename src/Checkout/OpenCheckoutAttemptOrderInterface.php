<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout;

/**
 * The order the checkout attempt this session has open holds.
 *
 * @since OPC-175
 */
interface OpenCheckoutAttemptOrderInterface
{
    /**
     * The early order of the open attempt while it can still be paid; null when
     * there is no open attempt, it has no order yet, or it is settled or ended.
     */
    public function orderId(): ?string;
}
