<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\ClientConfig\ValueObject;

/**
 * The version 1 envelope of docs/payments-keys.md. Binary fields are
 * base64url without padding.
 *
 * @since 1.4.0
 */
final class SealedEnvelope
{
    public function __construct(
        public readonly int $v,
        public readonly string $alg,
        public readonly string $serverPublicKey,
        public readonly string $salt,
        public readonly string $iv,
        public readonly int $issuedAt,
        public readonly string $ciphertext,
    ) {
    }
}
