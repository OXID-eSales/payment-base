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
 * Fixed-window rate limit per visitor: `validate:{sha1(clientIp)}:{minuteBucket}`, `minuteBucket` = floor(time / 60).
 *
 * Sprint 29 (VRL, Mythos O-08): the key used to be (pluginModuleId, sessionId). Both are chosen by the caller, so a
 * new plugin id or a fresh session started the count again. The address comes from TrustedProxyClientIp (as for the
 * webhook limiters) and is hashed, not stored. The shop JS calls this endpoint only on a Pay click, so one limit per
 * address is far above what shoppers behind a shared NAT produce. Limit: admin setting `iValidationApiRatePerMinute`.
 *
 * Priority 60: after the allowlist (53) and the rules check (56), so only a request for a real plugin is counted.
 */
class RateLimitGuard implements ValidationGuardInterface
{
    private const TTL_SECONDS = 60;

    public function __construct(
        private readonly RateLimitStoreInterface $store,
        private readonly RateLimitConfigInterface $config,
    ) {
    }

    public function check(ValidationRequestContext $ctx): ?GuardFailure
    {
        $count = $this->store->increment($this->buildKey($ctx), self::TTL_SECONDS);

        if ($count > $this->config->getLimitPerMinute()) {
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
        $client = $ctx->getClientIp() !== '' ? $ctx->getClientIp() : 'unknown';

        return 'validate:' . sha1($client) . ':' . (int) floor(time() / self::TTL_SECONDS);
    }
}
