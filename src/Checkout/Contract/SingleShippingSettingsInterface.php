<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Contract;

/**
 * Read accessor for "assign the delivery set automatically when the shop offers
 * only one" (blPaymentBaseAutoAssignSingleShipping).
 *
 * Separate from its payment sibling on purpose: a merchant may want one of the
 * two shortcuts and not the other.
 */
interface SingleShippingSettingsInterface
{
    public function isAutoAssignEnabled(): bool;
}
