<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Broker;

use InvalidArgumentException;

/**
 * Convention-based resolver for provider-specific event class names.
 *
 * The convention:
 *
 *     OxidEsales\Payments\{Canonical}\EventSystem\Event\{Canonical}{BaseName}
 *
 * Examples:
 *     'stripe' + 'RefundRequestEvent' → OxidEsales\Payments\Stripe\EventSystem\Event\StripeRefundRequestEvent
 *     'paypal' + 'CaptureRequestEvent' → OxidEsales\Payments\PayPal\EventSystem\Event\PayPalCaptureRequestEvent
 *     'klarna' + 'RefundRequestEvent' → OxidEsales\Payments\Klarna\EventSystem\Event\KlarnaRefundRequestEvent
 *
 * `{Canonical}` defaults to `ucfirst(strtolower($providerId))`. For
 * providers whose PascalCase form differs (e.g. `paypal` → `PayPal`),
 * call {@see registerCanonicalName()} once at boot.
 *
 * STRP-AUTOCAP-REFUND (2026-05-13) — replaces the per-provider translator
 * registration burden for the 95% case where the provider follows the
 * naming convention. {@see ProviderEventTranslatorInterface} remains the
 * explicit-override path for providers whose event class layout cannot
 * conform to the convention.
 *
 * @since 1.0.0
 */
class ConventionProviderEventResolver implements ProviderEventResolverInterface
{
    /**
     * Default namespace pattern. `{Canonical}` is substituted twice
     * (once for the namespace segment, once for the class-name prefix);
     * `{BaseName}` is substituted once for the suffix.
     */
    private const NAMESPACE_PATTERN = 'OxidEsales\\Payments\\{Canonical}\\EventSystem\\Event\\{Canonical}{BaseName}';

    /** @var array<string, string> lowercased provider id → canonical PascalCase name */
    private array $canonicalNames = [];

    public function resolveClassName(string $providerId, string $baseName): string
    {
        if ($providerId === '') {
            throw new InvalidArgumentException('providerId must not be empty');
        }
        if ($baseName === '') {
            throw new InvalidArgumentException('baseName must not be empty');
        }

        $canonical = $this->canonicalNames[strtolower($providerId)]
            ?? ucfirst(strtolower($providerId));

        /** @var class-string $fqcn */
        $fqcn = strtr(self::NAMESPACE_PATTERN, [
            '{Canonical}' => $canonical,
            '{BaseName}'  => $baseName,
        ]);

        return $fqcn;
    }

    public function registerCanonicalName(string $providerId, string $canonical): void
    {
        if ($providerId === '' || $canonical === '') {
            throw new InvalidArgumentException('providerId and canonical must not be empty');
        }
        $this->canonicalNames[strtolower($providerId)] = $canonical;
    }
}
