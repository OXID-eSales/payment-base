<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Service\Factory;

use OxidEsales\PaymentComponent\Adapter\PaymentAdapterInterface;

/**
 * Abstract factory for creating payment adapter instances.
 *
 * This is a provider-agnostic base class implementing PaymentAdapterFactoryInterface.
 * Provider-specific factories (e.g., StripeAdapterFactory, PayPalAdapterFactory)
 * should extend this class and implement the abstract methods.
 *
 * For DI constructors, always use PaymentAdapterFactoryInterface (LSP principle).
 *
 * @since 1.0.0
 */
abstract class PaymentAdapterFactory implements PaymentAdapterFactoryInterface
{
    /**
     * Creates an adapter for the specified provider.
     *
     * @param string $providerName The provider identifier (e.g., 'stripe', 'paypal')
     * @return PaymentAdapterInterface
     * @throws \InvalidArgumentException If the provider is not supported
     */
    abstract public function createAdapter(string $providerName): PaymentAdapterInterface;

    /**
     * Creates the default adapter for this factory.
     *
     * @return PaymentAdapterInterface
     */
    abstract public function createDefaultAdapter(): PaymentAdapterInterface;

    /**
     * Checks if a provider is supported by this factory.
     *
     * @param string $providerName The provider identifier to check
     * @return bool
     */
    abstract public function isProviderSupported(string $providerName): bool;

    /**
     * Gets all providers supported by this factory.
     *
     * @return array<string> List of provider identifiers
     */
    abstract public function getSupportedProviders(): array;
}
