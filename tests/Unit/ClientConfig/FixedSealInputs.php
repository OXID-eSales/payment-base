<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\ClientConfig;

use OpenSSLAsymmetricKey;
use OxidEsales\PaymentBase\ClientConfig\Contract\SealInputsInterface;

final class FixedSealInputs implements SealInputsInterface
{
    /**
     * @param array<int, string> $randomByLength raw bytes by requested length
     */
    public function __construct(
        private readonly OpenSSLAsymmetricKey $serverKey,
        private readonly array $randomByLength,
        private readonly int $now
    ) {
    }

    public function ephemeralPrivateKey(): OpenSSLAsymmetricKey
    {
        return $this->serverKey;
    }

    public function randomBytes(int $length): string
    {
        return $this->randomByLength[$length];
    }

    public function issuedAt(): int
    {
        return $this->now;
    }
}
