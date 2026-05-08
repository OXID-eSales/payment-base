<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Http;

interface RateLimiterInterface
{
    /**
     * Check whether the given identifier (typically a client IP) is allowed
     * to make another request within the current rate window.
     *
     * Returns true if the request is allowed, false if rate-limited.
     */
    public function isAllowed(string $identifier): bool;
}
