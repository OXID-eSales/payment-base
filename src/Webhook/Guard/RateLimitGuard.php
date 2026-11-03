<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Webhook\Guard;

use Closure;
use OxidEsales\PaymentBase\Validation\RateLimit\RateLimitStoreInterface;

/**
 * Fixed-window counter per provider and client in a store that outlives the
 * request (DoctrineRateLimitStore in production). PayPal's copy kept the counters
 * in the guard object, so it never limited anything.
 */
final class RateLimitGuard implements WebhookGuardInterface
{
    /**
     * @param Closure(): int|null $clock test seam; defaults to time()
     */
    public function __construct(
        private readonly RateLimitStoreInterface $store,
        private readonly string $provider,
        private readonly int $limitPerWindow = 120,
        private readonly int $windowSeconds = 60,
        private readonly ?Closure $clock = null,
    ) {
    }

    public function check(WebhookHttpRequest $request): WebhookGuardResult
    {
        $source = $request->clientIp !== '' ? $request->clientIp : 'unknown';
        $window = intdiv($this->now(), $this->windowSeconds);
        $key = sprintf('%s:webhook:%s:%d', $this->provider, sha1($source), $window);

        // Two windows of TTL: the counter must outlive its own window.
        if ($this->store->increment($key, $this->windowSeconds * 2) > $this->limitPerWindow) {
            return WebhookGuardResult::reject(429, 'rate_limited');
        }

        return WebhookGuardResult::pass();
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }
}
