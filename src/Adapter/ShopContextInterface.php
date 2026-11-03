<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter;

/**
 * The shop (subshop on EE) the current request runs in.
 *
 * @since VR-22
 */
interface ShopContextInterface
{
    public function getShopId(): int;
}
