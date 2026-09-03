<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Service;

/**
 * Sprint 09 — whether an order that ends returns its vouchers to the pool.
 *
 * @since 2026-09-03
 */
interface VoucherReleaseSettingsInterface
{
    public function isReleaseOnOrderEndEnabled(): bool;
}
