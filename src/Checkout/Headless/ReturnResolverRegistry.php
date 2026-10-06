<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Headless;

use OxidEsales\PaymentBase\Return\ReturnResolverInterface;

/**
 * @since 3.0.0
 */
final class ReturnResolverRegistry implements ReturnResolverRegistryInterface
{
    /** @var array<string, ReturnResolverInterface> lowercase provider name => resolver */
    private array $resolvers = [];

    /**
     * @param iterable<string, ReturnResolverInterface> $resolvers indexed by the tag's `provider` attribute
     */
    public function __construct(iterable $resolvers)
    {
        foreach ($resolvers as $provider => $resolver) {
            $this->resolvers[strtolower((string) $provider)] = $resolver;
        }
    }

    public function forProvider(string $providerName): ?ReturnResolverInterface
    {
        return $this->resolvers[strtolower($providerName)] ?? null;
    }
}
