<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\ClientConfig\Contract;

/**
 * The shapes of a provider's secrets, so that ClientConfigGuard can refuse a value that looks like one before it is
 * sealed for a browser. payment-base knows no provider: each provider module registers one implementation with the
 * tag `payment_base.client_config_secret_pattern_provider`.
 *
 * @since 1.7.0
 */
interface ClientConfigSecretPatternProviderInterface
{
    /**
     * Complete regular expressions (delimiters included, anchored where the shape is a prefix or a fixed length).
     *
     * @return list<string>
     */
    public function secretValuePatterns(): array;
}
