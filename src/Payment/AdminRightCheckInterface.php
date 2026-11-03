<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Payment;

/**
 * Whether an admin holds a right of the admin rights catalogue (`PaymentRightsProvider`), asked outside the admin
 * API's own request: the classic admin has no token to read the admin from.
 *
 * @since 1.7.0
 */
interface AdminRightCheckInterface
{
    public function allows(string $userId, int $shopId, string $right): bool;
}
