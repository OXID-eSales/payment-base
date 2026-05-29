<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation\RateLimit;

/**
 * Rate-limit config that supports per-PSP overrides via tagged iterator.
 *
 * Resolution order:
 *  1. Iterate `$overrides` (tagged `oe.payment_base.rate_limit_override`).
 *     First match by `getPluginModuleId()` wins.
 *  2. Fall back to `$globalDefault`.
 *
 * Sprint 119 (STRP-129) — Phase A2.
 */
final class ConfigurableRateLimitConfig implements RateLimitConfigInterface
{
    /** @var iterable<RateLimitOverrideInterface> */
    private iterable $overrides;

    /**
     * @param iterable<RateLimitOverrideInterface> $overrides tagged iterator
     */
    public function __construct(
        private readonly int $globalDefault,
        iterable $overrides,
    ) {
        $this->overrides = $overrides;
    }

    public function getLimitForPlugin(string $pluginModuleId): int
    {
        foreach ($this->overrides as $override) {
            if ($override->getPluginModuleId() === $pluginModuleId) {
                return $override->getLimitPerMinute();
            }
        }

        return $this->globalDefault;
    }
}
