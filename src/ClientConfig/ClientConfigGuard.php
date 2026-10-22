<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\ClientConfig;

use OxidEsales\PaymentBase\ClientConfig\Contract\ClientConfigGuardInterface;
use OxidEsales\PaymentBase\ClientConfig\Exception\UnsafeClientConfig;

/**
 * Refuses anything that looks like a secret before it is sealed for a
 * browser: names the provider did not declare (when it declared any), values
 * with a provider secret shape (Stripe sk_/rk_/whsec_, Mollie live_/test_
 * API keys and access_ tokens), key names that contain "secret" or are an
 * API key, and every value that is not a string or a bool. The provider's
 * name allow-list is the first defence, the deny shapes the last.
 *
 * @since 1.4.0
 */
final class ClientConfigGuard implements ClientConfigGuardInterface
{
    private const SECRET_VALUE_PREFIXES = ['sk_', 'rk_', 'whsec_', 'access_'];
    // Mollie API keys: live_/test_ plus exactly 30 characters. Anchored, so "test_mode_enabled" is not one.
    private const MOLLIE_API_KEY_PATTERN = '/^(live|test)_[A-Za-z0-9]{30}$/';
    private const API_KEY_NAMES = ['apikey', 'api_key'];
    private const SECRET_KEY_FRAGMENT = 'secret';

    /**
     * @param array<mixed> $values
     * @param list<string>|null $allowedNames
     *
     * @return array<string, string|bool>
     */
    public function assertPublic(array $values, ?array $allowedNames = null): array
    {
        $public = [];
        foreach ($values as $key => $value) {
            $public[$this->checkedKey($key, $allowedNames)] = $this->checkedValue($key, $value);
        }

        return $public;
    }

    /**
     * @param list<string>|null $allowedNames
     */
    private function checkedKey(int|string $key, ?array $allowedNames): string
    {
        if (!is_string($key)) {
            throw UnsafeClientConfig::forKey((string) $key, 'keys must be names');
        }
        if ($allowedNames !== null && !in_array($key, $allowedNames, true)) {
            throw UnsafeClientConfig::forKey($key, 'the name is not in the provider\'s allow-list');
        }

        $lower = strtolower($key);
        if (str_contains($lower, self::SECRET_KEY_FRAGMENT) || in_array($lower, self::API_KEY_NAMES, true)) {
            throw UnsafeClientConfig::forKey($key, 'the name looks secret');
        }

        return $key;
    }

    private function checkedValue(int|string $key, mixed $value): string|bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (!is_string($value)) {
            throw UnsafeClientConfig::forKey((string) $key, 'only string and bool values are allowed');
        }
        if ($this->looksLikeSecret($value)) {
            throw UnsafeClientConfig::forKey((string) $key, 'the value looks like a secret');
        }

        return $value;
    }

    private function looksLikeSecret(string $value): bool
    {
        foreach (self::SECRET_VALUE_PREFIXES as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return true;
            }
        }

        return preg_match(self::MOLLIE_API_KEY_PATTERN, $value) === 1;
    }
}
