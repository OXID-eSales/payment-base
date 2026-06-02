<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation\Guard;

/**
 * Guard #4 — rejects cross-origin requests.
 *
 * Checks Origin header first; falls back to Referer. Both absent → reject.
 * Host comparison only (scheme + host, no port comparison for 80/443).
 *
 * Priority 40.
 *
 * Sprint 119 (STRP-129) — Phase A2.
 */
class SameOriginGuard implements ValidationGuardInterface
{
    public function __construct(
        private readonly ShopUrlResolverInterface $shopUrlResolver,
    ) {
    }

    public function check(ValidationRequestContext $ctx): ?GuardFailure
    {
        $candidate = $ctx->getOriginHeader() ?? $ctx->getRefererHeader();

        if ($candidate === null) {
            return GuardFailure::httpStatus(403, self::class);
        }

        if ($this->hostsMatch($candidate)) {
            return null;
        }

        return GuardFailure::httpStatus(403, self::class);
    }

    public function getPriority(): int
    {
        return 40;
    }

    private function hostsMatch(string $candidateUrl): bool
    {
        $shopHost = parse_url($this->shopUrlResolver->getShopUrl(), PHP_URL_HOST);
        $candidateHost = parse_url($candidateUrl, PHP_URL_HOST);

        if (!is_string($shopHost) || !is_string($candidateHost)) {
            return false;
        }

        return strtolower($shopHost) === strtolower($candidateHost);
    }
}
