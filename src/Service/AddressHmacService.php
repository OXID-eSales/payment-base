<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Service;

use InvalidArgumentException;

/**
 * HMAC signing/verification for delivery address hashes.
 *
 * Sprint 68b (M9): Wraps OXID's MD5 address hash with HMAC-SHA256
 * using a server-side secret. Prevents hash forgery by attackers
 * with DB write access.
 *
 * @since 2.1.0
 */
final class AddressHmacService implements AddressHmacServiceInterface
{
    private const ALGORITHM = 'sha256';

    public function __construct(
        private readonly string $secret
    ) {
        if ($secret === '') {
            throw new InvalidArgumentException('Address HMAC secret must not be empty');
        }
    }

    public function sign(string $addressHash): string
    {
        if ($addressHash === '') {
            return '';
        }

        return hash_hmac(self::ALGORITHM, $addressHash, $this->secret);
    }

    public function verify(string $addressHash, string $hmac): bool
    {
        if ($addressHash === '' || $hmac === '') {
            return false;
        }

        return hash_equals($this->sign($addressHash), $hmac);
    }
}
