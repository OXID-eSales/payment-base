<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Auth;

/**
 * Where a fetched JWKS document lives for its bounded TTL.
 */
interface JwksCacheInterface
{
    public function get(string $key): ?string;

    public function set(string $key, string $value, int $ttlSeconds): void;
}
