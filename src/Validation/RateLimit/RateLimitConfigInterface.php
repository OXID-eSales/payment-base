<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation\RateLimit;

/**
 * Returns the effective rate-limit (requests / minute) for a given plugin.
 *
 * The default implementation checks registered per-PSP overrides first,
 * then falls back to the global admin setting.
 *
 * Sprint 119 (STRP-129) — Phase A2.
 */
interface RateLimitConfigInterface
{
    public function getLimitForPlugin(string $pluginModuleId): int;
}
