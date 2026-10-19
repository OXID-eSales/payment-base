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
 * browser: values starting with a provider secret prefix, key names that
 * contain "secret" or are an API key, and every value that is not a string
 * or a bool. The providers' allow-list is the first defence, this the last.
 *
 * @since 1.4.0
 */
final class ClientConfigGuard implements ClientConfigGuardInterface
{
    private const SECRET_VALUE_PREFIXES = ['sk_', 'rk_', 'whsec_'];
    private const API_KEY_NAMES = ['apikey', 'api_key'];
    private const SECRET_KEY_FRAGMENT = 'secret';

    /**
     * @param array<mixed> $values
     *
     * @return array<string, string|bool>
     */
    public function assertPublic(array $values): array
    {
        $public = [];
        foreach ($values as $key => $value) {
            $public[$this->checkedKey($key)] = $this->checkedValue($key, $value);
        }

        return $public;
    }

    private function checkedKey(int|string $key): string
    {
        if (!is_string($key)) {
            throw UnsafeClientConfig::forKey((string) $key, 'keys must be names');
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
        foreach (self::SECRET_VALUE_PREFIXES as $prefix) {
            if (str_starts_with($value, $prefix)) {
                throw UnsafeClientConfig::forKey((string) $key, 'the value looks like a secret');
            }
        }

        return $value;
    }
}
