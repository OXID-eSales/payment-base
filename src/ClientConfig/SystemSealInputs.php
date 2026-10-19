<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\ClientConfig;

use InvalidArgumentException;
use OpenSSLAsymmetricKey;
use OxidEsales\PaymentBase\ClientConfig\Contract\SealInputsInterface;
use RuntimeException;

/**
 * Production inputs: a fresh P-256 key, the CSPRNG and the system clock.
 *
 * @since 1.4.0
 */
final class SystemSealInputs implements SealInputsInterface
{
    private const CURVE = 'prime256v1';

    public function ephemeralPrivateKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => self::CURVE]);

        return $key ?: throw new RuntimeException('OpenSSL cannot create an EC key');
    }

    public function randomBytes(int $length): string
    {
        if ($length < 1) {
            throw new InvalidArgumentException('Cannot draw less than one random byte');
        }

        return random_bytes($length);
    }

    public function issuedAt(): int
    {
        return time();
    }
}
