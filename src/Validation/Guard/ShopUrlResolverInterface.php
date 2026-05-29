<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation\Guard;

/**
 * Provides the canonical shop URL for same-origin checks.
 *
 * Narrow interface so `SameOriginGuard` has no direct OXID dependency,
 * and unit tests can inject a stub without booting the shop.
 *
 * Sprint 119 (STRP-129) — Phase A2.
 */
interface ShopUrlResolverInterface
{
    public function getShopUrl(): string;
}
