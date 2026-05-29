<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation\RateLimit;

/**
 * Narrow counter store for the sliding-window rate-limiter.
 *
 * The key uniquely identifies a (plugin, session, time-bucket) tuple.
 * `increment` atomically increments the counter and returns the new value.
 * After `ttlSeconds` the counter is silently reset.
 *
 * Two implementations:
 *  - `InMemoryRateLimitStore` — in-process, for unit tests only
 *  - `DoctrineRateLimitStore`  — Doctrine DBAL, re-uses oe_payments_idempotency
 *
 * Sprint 119 (STRP-129) — Phase A2.
 */
interface RateLimitStoreInterface
{
    /**
     * Increment the counter for `$key` and return the new value.
     * The counter expires after `$ttlSeconds` seconds.
     */
    public function increment(string $key, int $ttlSeconds): int;
}
