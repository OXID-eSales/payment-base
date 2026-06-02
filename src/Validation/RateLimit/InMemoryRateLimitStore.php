<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation\RateLimit;

/**
 * In-process rate-limit store — for unit tests only.
 *
 * Not process-safe; each request spawns a fresh PHP process so this is
 * only used in test isolation. The Doctrine-backed store is used in production.
 *
 * Sprint 119 (STRP-129) — Phase A2.
 */
class InMemoryRateLimitStore implements RateLimitStoreInterface
{
    /** @var array<string, array{count: int, expiresAt: float}> */
    private array $counters = [];

    public function increment(string $key, int $ttlSeconds): int
    {
        $now = microtime(true);

        $this->expireIfStale($key, $now);
        $this->initIfAbsent($key, $now, $ttlSeconds);

        $this->counters[$key]['count']++;

        return $this->counters[$key]['count'];
    }

    private function expireIfStale(string $key, float $now): void
    {
        if (!isset($this->counters[$key])) {
            return;
        }

        if ($this->counters[$key]['expiresAt'] <= $now) {
            unset($this->counters[$key]);
        }
    }

    private function initIfAbsent(string $key, float $now, int $ttlSeconds): void
    {
        if (!isset($this->counters[$key])) {
            $this->counters[$key] = [
                'count' => 0,
                'expiresAt' => $now + $ttlSeconds,
            ];
        }
    }
}
