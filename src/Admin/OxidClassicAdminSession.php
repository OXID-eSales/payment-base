<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Admin;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentBase\Payment\ClassicAdminSessionInterface;

/**
 * The admin of the OXID admin session: the admin login keeps the user id in the session variable `auth`.
 *
 * @since 1.7.0
 */
final class OxidClassicAdminSession implements ClassicAdminSessionInterface
{
    public function userId(): string
    {
        $userId = Registry::getSession()->getVariable('auth');

        return is_string($userId) ? $userId : '';
    }

    public function shopId(): int
    {
        return (int) Registry::getConfig()->getShopId();
    }
}
