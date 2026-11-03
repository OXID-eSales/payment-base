<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Auth;

/**
 * Shared across requests through APCu. Without APCu nothing is cached, so
 * every lookup fetches the document: slower, never less strict.
 */
final class ApcuJwksCache implements JwksCacheInterface
{
    private const KEY_PREFIX = 'mcp_jwks:';

    public function get(string $key): ?string
    {
        if (!$this->isApcuAvailable()) {
            return null;
        }

        $found = false;
        $value = apcu_fetch(self::KEY_PREFIX . $key, $found);

        return $found && is_string($value) ? $value : null;
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        if (!$this->isApcuAvailable()) {
            return;
        }

        apcu_store(self::KEY_PREFIX . $key, $value, $ttlSeconds);
    }

    private function isApcuAvailable(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }
}
