<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Http;

/**
 * APCu-based fixed-window rate limiter.
 *
 * Uses APCu shared memory for atomic increment counters with TTL-based expiration.
 * Falls back to allow-all if APCu is not available (defense-in-depth — rate limiting
 * is one layer, not the only protection).
 */
class ApcuRateLimiter implements RateLimiterInterface
{
    public function __construct(
        private readonly int $maxRequests = 60,
        private readonly int $windowSeconds = 60,
        private readonly string $keyPrefix = 'mcp_rate:'
    ) {
    }

    public function isAllowed(string $identifier): bool
    {
        if (!$this->isApcuAvailable()) {
            return true;
        }

        $key = $this->keyPrefix . $identifier;

        $success = false;
        $count = apcu_inc($key, 1, $success);

        if (!$success) {
            apcu_store($key, 1, $this->windowSeconds);
            return true;
        }

        return $count <= $this->maxRequests;
    }

    private function isApcuAvailable(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }
}
