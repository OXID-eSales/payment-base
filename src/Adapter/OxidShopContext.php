<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter;

use OxidEsales\Eshop\Core\Registry;

/**
 * The shop of the request is OXID's configuration; Registry is the framework's
 * only way to reach it from a service.
 *
 * @since VR-22
 */
class OxidShopContext implements ShopContextInterface
{
    public function getShopId(): int
    {
        return (int) Registry::getConfig()->getShopId();
    }
}
