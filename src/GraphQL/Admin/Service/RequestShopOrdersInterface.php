<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

/**
 * Shop scope of the admin API: an order of another shop does not exist for the request, whatever id the client names.
 *
 * @since 1.5.0
 */
interface RequestShopOrdersInterface
{
    public function contains(string $orderId): bool;
}
