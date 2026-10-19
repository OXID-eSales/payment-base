<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\ClientConfig\Contract;

use OxidEsales\PaymentBase\ClientConfig\Exception\UnknownClientConfigProvider;

/**
 * @since 1.4.0
 */
interface ClientConfigProviderRegistryInterface
{
    /**
     * @throws UnknownClientConfigProvider
     */
    public function get(string $providerId): ClientConfigProviderInterface;
}
