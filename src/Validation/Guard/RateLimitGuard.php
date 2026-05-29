<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation\Guard;

use OxidEsales\PaymentBase\Validation\RateLimit\RateLimitConfigInterface;
use OxidEsales\PaymentBase\Validation\RateLimit\RateLimitStoreInterface;

/**
 * Guard #6 — sliding-window rate-limiter keyed by (pluginModuleId, sessionId).
 *
 * Key format: `validate:{pluginModuleId}:{sessionId}:{minuteBucket}`
 * where `minuteBucket` is `floor(time / 60)`.
 *
 * Limit per plugin is resolved via RateLimitConfigInterface which checks
 * per-PSP overrides first, then the global admin setting.
 *
 * Priority 60.
 *
 * Sprint 119 (STRP-129) — Phase A2.
 */
final class RateLimitGuard implements ValidationGuardInterface
{
    private const TTL_SECONDS = 60;

    public function __construct(
        private readonly RateLimitStoreInterface $store,
        private readonly RateLimitConfigInterface $config,
    ) {
    }

    public function check(ValidationRequestContext $ctx): ?GuardFailure
    {
        $key = $this->buildKey($ctx);
        $limit = $this->config->getLimitForPlugin($ctx->getPluginModuleId());
        $count = $this->store->increment($key, self::TTL_SECONDS);

        if ($count > $limit) {
            return GuardFailure::httpStatus(429, self::class);
        }

        return null;
    }

    public function getPriority(): int
    {
        return 60;
    }

    private function buildKey(ValidationRequestContext $ctx): string
    {
        $bucket = (int) floor(time() / self::TTL_SECONDS);

        return 'validate:' . $ctx->getPluginModuleId() . ':' . ($ctx->getSessionId() ?? '') . ':' . $bucket;
    }
}
