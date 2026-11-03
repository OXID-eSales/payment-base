<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Payment;

/**
 * Whether the shop demands a second factor for money movements. Where it does, a door that cannot ask for one (the
 * classic admin) cannot move money.
 *
 * @since 1.7.0
 */
interface SecondFactorPolicyInterface
{
    public function isRequired(): bool;
}
