<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter;

/**
 * The `shp` URL parameter that routes a request into its shop on an EE multishop. The one place that
 * spells it for the payment providers.
 *
 * Two rules, because two kinds of request exist:
 * - {@see self::always()} for a URL a third party calls later, without the shopper's session (a
 *   provider's webhook, return and cancel URLs). It names every shop, shop 1 too.
 * - {@see self::forSubshop()} for a URL the shopper's browser calls inside the running checkout. A
 *   shop with one shop id (shop 1) gets nothing added, so its requests stay as they were.
 *
 * Static pure functions, the idiom of {@see ShopCurrency}.
 */
final class ShopParameter
{
    private const DEFAULT_SHOP_ID = 1;

    public static function always(int $shopId): string
    {
        return '&shp=' . $shopId;
    }

    public static function forSubshop(int $shopId): string
    {
        return $shopId === self::DEFAULT_SHOP_ID ? '' : self::always($shopId);
    }
}
