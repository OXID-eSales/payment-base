<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

/**
 * Whether the admin of the request holds the built-in superadmin role in the shop. Providers ask it before they let
 * an API key, a secret or a connection change: ADMIN_PAYMENT_CONFIG alone does not reach that far. It never throws:
 * whatever cannot be decided is "not a superadmin".
 *
 * @since 1.7.0
 */
interface AdminSuperadminCheckInterface
{
    public function isSuperadmin(): bool;
}
