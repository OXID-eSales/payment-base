<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Headless;

use OxidEsales\PaymentBase\Return\ReturnResolverInterface;

/**
 * The provider modules' return resolvers by provider name - registered with
 * `- { name: oe.payment.return_resolver, provider: stripe }`.
 *
 * Sprint 15 / S6 (GRAPH-QL). A provider's Twig return controller hands its
 * resolver to CheckoutReturnResponder; the headless return mutation needs
 * the same resolver without knowing the provider's class.
 *
 * @since 3.0.0
 */
interface ReturnResolverRegistryInterface
{
    public function forProvider(string $providerName): ?ReturnResolverInterface;
}
