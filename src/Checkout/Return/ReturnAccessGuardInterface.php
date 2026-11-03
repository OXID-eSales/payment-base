<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Return;

interface ReturnAccessGuardInterface
{
    public function check(ReturnAccessRequest $request): ReturnAccessDecision;
}
