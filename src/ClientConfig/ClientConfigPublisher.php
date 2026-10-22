<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\ClientConfig;

use OxidEsales\PaymentBase\ClientConfig\Contract\AllowListedClientConfigProviderInterface;
use OxidEsales\PaymentBase\ClientConfig\Contract\ClientConfigGuardInterface;
use OxidEsales\PaymentBase\ClientConfig\Contract\ClientConfigProviderRegistryInterface;
use OxidEsales\PaymentBase\ClientConfig\Contract\ClientConfigPublisherInterface;
use OxidEsales\PaymentBase\ClientConfig\Contract\ClientConfigSealerInterface;
use OxidEsales\PaymentBase\ClientConfig\ValueObject\SealedEnvelope;

/**
 * provider id -> its public values -> guard against secrets (and, when the
 * provider declared its names, against any other name) -> sealed for the
 * browser that asked.
 *
 * @since 1.4.0
 */
final class ClientConfigPublisher implements ClientConfigPublisherInterface
{
    public function __construct(
        private readonly ClientConfigProviderRegistryInterface $providers,
        private readonly ClientConfigGuardInterface $guard,
        private readonly ClientConfigSealerInterface $sealer,
    ) {
    }

    public function sealedFor(string $providerId, string $clientPublicKey, string $tokenId): SealedEnvelope
    {
        $provider = $this->providers->get($providerId);
        $values = $this->guard->assertPublic(
            $provider->publicValues(),
            $provider instanceof AllowListedClientConfigProviderInterface ? $provider->publicValueNames() : null
        );

        return $this->sealer->seal($providerId, $tokenId, $clientPublicKey, $values);
    }
}
