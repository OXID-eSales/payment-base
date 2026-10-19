<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\ClientConfig\Contract;

use OxidEsales\PaymentBase\ClientConfig\Exception\InvalidClientPublicKey;
use OxidEsales\PaymentBase\ClientConfig\Exception\UnknownClientConfigProvider;
use OxidEsales\PaymentBase\ClientConfig\Exception\UnsafeClientConfig;
use OxidEsales\PaymentBase\ClientConfig\ValueObject\SealedEnvelope;

/**
 * @since 1.4.0
 */
interface ClientConfigPublisherInterface
{
    /**
     * @throws UnknownClientConfigProvider
     * @throws UnsafeClientConfig
     * @throws InvalidClientPublicKey
     */
    public function sealedFor(string $providerId, string $clientPublicKey, string $tokenId): SealedEnvelope;
}
