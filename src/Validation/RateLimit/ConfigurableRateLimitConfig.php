<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation\RateLimit;

/**
 * Rate-limit config: one global limit per plugin and minute.
 *
 * Sprint 119 (STRP-129) — Phase A2. VR-24 DC-PB7e: the per-PSP override extension point had no implementer in any
 * module and is gone.
 */
class ConfigurableRateLimitConfig implements RateLimitConfigInterface
{
    public function __construct(
        private readonly int $globalDefault,
    ) {
    }

    public function getLimitForPlugin(string $pluginModuleId): int
    {
        return $this->globalDefault;
    }
}
