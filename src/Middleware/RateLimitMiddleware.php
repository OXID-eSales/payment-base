<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Middleware;

/**
 * Rate limiting middleware for API abuse prevention.
 *
 * Tracks API calls per IP address and enforces configurable rate limits
 * using a sliding window algorithm.
 *
 * Default: 10 calls per 60 seconds (1 minute)
 *
 * Usage:
 * ```php
 * $rateLimiter = new RateLimitMiddleware();
 * if (!$rateLimiter->checkRateLimit($ipAddress)) {
 *     throw new TooManyRequestsException('Rate limit exceeded');
 * }
 * ```
 *
 * @since 1.0.0
 */
class RateLimitMiddleware
{
    private const DEFAULT_MAX_CALLS = 10;
    private const DEFAULT_WINDOW_SECONDS = 60;

    /**
     * @var array<string, array{calls: int, windowStart: int}> Tracked requests by IP
     */
    private array $requests = [];

    public function __construct(
        private int $maxCalls = self::DEFAULT_MAX_CALLS,
        private int $windowSeconds = self::DEFAULT_WINDOW_SECONDS
    ) {
    }

    /**
     * Check if a request from the given IP should be allowed.
     *
     * @param string $ipAddress IP address of the requester
     * @return bool True if allowed, false if rate limit exceeded
     */
    public function checkRateLimit(string $ipAddress): bool
    {
        $this->cleanupExpiredEntries();

        $now = time();

        if (!isset($this->requests[$ipAddress])) {
            // First request from this IP
            $this->requests[$ipAddress] = [
                'calls' => 1,
                'windowStart' => $now,
            ];
            return true;
        }

        $window = &$this->requests[$ipAddress];

        // Check if window has expired
        if (($now - $window['windowStart']) >= $this->windowSeconds) {
            // Reset window
            $window['calls'] = 1;
            $window['windowStart'] = $now;
            return true;
        }

        // Check if within rate limit
        if ($window['calls'] < $this->maxCalls) {
            $window['calls']++;
            return true;
        }

        // Rate limit exceeded
        return false;
    }

    /**
     * Get remaining calls available for an IP address.
     *
     * @param string $ipAddress IP address to check
     * @return int Number of remaining calls (0 if limit exceeded)
     */
    public function getRemainingCalls(string $ipAddress): int
    {
        $this->cleanupExpiredEntries();

        if (!isset($this->requests[$ipAddress])) {
            return $this->maxCalls;
        }

        $window = $this->requests[$ipAddress];
        $now = time();

        // Check if window expired
        if (($now - $window['windowStart']) >= $this->windowSeconds) {
            return $this->maxCalls;
        }

        $remaining = $this->maxCalls - $window['calls'];
        return max(0, $remaining);
    }

    /**
     * Get seconds until rate limit resets for an IP.
     *
     * @param string $ipAddress IP address to check
     * @return int Seconds until reset (0 if not limited)
     */
    public function getRetryAfter(string $ipAddress): int
    {
        if (!isset($this->requests[$ipAddress])) {
            return 0;
        }

        $window = $this->requests[$ipAddress];
        $now = time();

        $elapsed = $now - $window['windowStart'];

        if ($elapsed >= $this->windowSeconds) {
            return 0;
        }

        // Only return retry-after if limit is actually exceeded
        if ($window['calls'] < $this->maxCalls) {
            return 0;
        }

        return $this->windowSeconds - $elapsed;
    }

    /**
     * Reset rate limit tracking for a specific IP.
     *
     * @param string $ipAddress IP address to reset
     * @return void
     */
    public function resetRateLimit(string $ipAddress): void
    {
        unset($this->requests[$ipAddress]);
    }

    /**
     * Clean up expired tracking entries.
     *
     * @return void
     */
    private function cleanupExpiredEntries(): void
    {
        $now = time();

        foreach ($this->requests as $ip => $window) {
            if (($now - $window['windowStart']) >= $this->windowSeconds) {
                unset($this->requests[$ip]);
            }
        }
    }
}
