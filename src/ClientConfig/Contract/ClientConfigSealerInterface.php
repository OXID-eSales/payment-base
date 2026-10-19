<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\ClientConfig\Contract;

use OxidEsales\PaymentBase\ClientConfig\Exception\InvalidClientPublicKey;
use OxidEsales\PaymentBase\ClientConfig\ValueObject\SealedEnvelope;

/**
 * @since 1.4.0
 */
interface ClientConfigSealerInterface
{
    /**
     * @param array<string, string|bool> $values the allow-listed public values
     *
     * @throws InvalidClientPublicKey
     */
    public function seal(string $providerId, string $tokenId, string $clientPublicKey, array $values): SealedEnvelope;
}
