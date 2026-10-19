<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\ClientConfig\Contract;

use OpenSSLAsymmetricKey;

/**
 * Everything non-deterministic in a seal: the ephemeral server key, the
 * random bytes (salt, iv) and the clock. Injected so a test can reproduce the
 * shared test vector exactly; production uses SystemSealInputs.
 *
 * @since 1.4.0
 */
interface SealInputsInterface
{
    /**
     * A P-256 private key that is used for one seal only.
     */
    public function ephemeralPrivateKey(): OpenSSLAsymmetricKey;

    public function randomBytes(int $length): string;

    /**
     * Unix time in seconds.
     */
    public function issuedAt(): int;
}
