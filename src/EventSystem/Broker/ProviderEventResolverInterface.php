<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Broker;

/**
 * Resolves a provider-specific event class name from a provider id and a
 * generic event base name, using a naming convention so payment-base does
 * not need a per-provider translator service for the 95% case.
 *
 * The default implementation builds:
 *
 *     OxidEsales\Payments\{Canonical}\EventSystem\Event\{Canonical}{BaseName}
 *
 * where `{Canonical} = ucfirst(strtolower($providerId))` with optional
 * canonical-name overrides for providers whose PascalCase differs from
 * `ucfirst()` (e.g. `paypal` → `PayPal`).
 *
 * STRP-AUTOCAP-REFUND (2026-05-13) — added so a third-party payment
 * provider can drop in without registering a {@see ProviderEventTranslatorInterface}.
 *
 * @since 1.0.0
 */
interface ProviderEventResolverInterface
{
    /**
     * Resolve the fully-qualified class name for the provider-specific
     * event class. The class may not actually be loadable — the caller
     * is responsible for `class_exists()` checking before instantiating.
     *
     * @param string $providerId Lower-case provider identifier from
     *                           `$contract->getProvider()` (e.g. 'stripe').
     * @param string $baseName   Generic base name without provider prefix
     *                           (e.g. 'RefundRequestEvent', 'CaptureRequestEvent').
     *
     * @return class-string Fully-qualified class name following the
     *                      provider-event naming convention.
     *
     * @throws \InvalidArgumentException If $providerId or $baseName is empty.
     */
    public function resolveClassName(string $providerId, string $baseName): string;

    /**
     * Register the canonical PascalCase name for a provider id whose
     * `ucfirst()` is not the canonical PascalCase form. Optional —
     * providers like `stripe` whose canonical is `Stripe == ucfirst('stripe')`
     * do not need to register anything.
     */
    public function registerCanonicalName(string $providerId, string $canonical): void;
}
