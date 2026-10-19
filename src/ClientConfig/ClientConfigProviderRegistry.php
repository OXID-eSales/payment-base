<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\ClientConfig;

use LogicException;
use OxidEsales\PaymentBase\ClientConfig\Contract\ClientConfigProviderInterface;
use OxidEsales\PaymentBase\ClientConfig\Contract\ClientConfigProviderRegistryInterface;
use OxidEsales\PaymentBase\ClientConfig\Exception\UnknownClientConfigProvider;

/**
 * Collects the providers tagged `payment_base.client_config_provider`.
 * The map is built on first use so a shop that never asks pays nothing.
 *
 * @since 1.4.0
 */
final class ClientConfigProviderRegistry implements ClientConfigProviderRegistryInterface
{
    /** @var array<string, ClientConfigProviderInterface>|null */
    private ?array $byId = null;

    /**
     * @param iterable<ClientConfigProviderInterface> $providers
     */
    public function __construct(private readonly iterable $providers)
    {
    }

    public function get(string $providerId): ClientConfigProviderInterface
    {
        return $this->index()[$providerId] ?? throw new UnknownClientConfigProvider($providerId);
    }

    /**
     * @return array<string, ClientConfigProviderInterface>
     */
    private function index(): array
    {
        if ($this->byId !== null) {
            return $this->byId;
        }

        $byId = [];
        foreach ($this->providers as $provider) {
            if (isset($byId[$provider->providerId()])) {
                throw new LogicException(sprintf('Two client config providers claim "%s"', $provider->providerId()));
            }
            $byId[$provider->providerId()] = $provider;
        }

        return $this->byId = $byId;
    }
}
