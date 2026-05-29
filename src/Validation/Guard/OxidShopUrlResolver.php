<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation\Guard;

use OxidEsales\Eshop\Core\Registry;

/**
 * OXID-backed shop URL resolver.
 *
 * Reads the canonical shop URL from OXID's Config object.
 * Wrapped behind ShopUrlResolverInterface so SameOriginGuard
 * has no direct Registry dependency.
 *
 * Sprint 119 (STRP-129) — Phase A2.
 */
final class OxidShopUrlResolver implements ShopUrlResolverInterface
{
    public function getShopUrl(): string
    {
        // @phpstan-ignore-next-line — OXID Registry is the documented seam
        return (string) Registry::getConfig()->getShopUrl();
    }
}
