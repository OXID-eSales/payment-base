<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Contract;

/**
 * Read accessor for "assign the payment method automatically when the shop
 * offers only one" (blPaymentBaseAutoAssignSinglePayment).
 */
interface SinglePaymentSettingsInterface
{
    public function isAutoAssignEnabled(): bool;
}
