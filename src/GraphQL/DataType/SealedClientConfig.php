<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\DataType;

use OxidEsales\PaymentBase\ClientConfig\ValueObject\SealedEnvelope;
use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Annotations\Type;

/**
 * Result of `paymentClientConfig`: the sealed envelope, version 1
 * (docs/payments-keys.md). Binary fields are base64url without padding.
 *
 * @since 1.4.0
 */
#[Type]
final class SealedClientConfig
{
    public function __construct(private readonly SealedEnvelope $envelope)
    {
    }

    #[Field]
    public function v(): int
    {
        return $this->envelope->v;
    }

    #[Field]
    public function alg(): string
    {
        return $this->envelope->alg;
    }

    #[Field]
    public function serverPublicKey(): string
    {
        return $this->envelope->serverPublicKey;
    }

    #[Field]
    public function salt(): string
    {
        return $this->envelope->salt;
    }

    #[Field]
    public function iv(): string
    {
        return $this->envelope->iv;
    }

    // GraphQLite strips a leading "is" from method names: without the name this is `suedAt`.
    #[Field(name: 'issuedAt')]
    public function issuedAt(): int
    {
        return $this->envelope->issuedAt;
    }

    #[Field]
    public function ciphertext(): string
    {
        return $this->envelope->ciphertext;
    }
}
