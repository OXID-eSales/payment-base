<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation\RateLimit;

/**
 * Per-PSP rate-limit override.
 *
 * PSP modules opt in by tagging their implementation with
 * `oe.payment_base.rate_limit_override`. ConfigurableRateLimitConfig
 * iterates all overrides and uses the first match.
 *
 * Sprint 119 (STRP-129) — Phase A2.
 */
interface RateLimitOverrideInterface
{
    public function getPluginModuleId(): string;

    public function getLimitPerMinute(): int;
}
