<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Admin;

use OxidEsales\PaymentBase\Admin\Contract\PaymentPanelProviderInterface;
use OxidEsales\PaymentBase\Admin\Contract\PaymentPanelRegistryInterface;
use OxidEsales\PaymentBase\Admin\Panel\PaymentPanelContext;

/**
 * Collects every panel provider tagged `oe.payment.admin_panel` and
 * hands the first one that supports a given order to the admin
 * controller. Returns null when nothing supports — the wrapper
 * template then renders the "no registered online payment provider"
 * notice.
 */
final class PaymentPanelRegistry implements PaymentPanelRegistryInterface
{
    /** @var list<PaymentPanelProviderInterface> */
    private array $providers;

    /**
     * @param iterable<PaymentPanelProviderInterface> $providers
     */
    public function __construct(iterable $providers)
    {
        $this->providers = [];
        foreach ($providers as $provider) {
            $this->providers[] = $provider;
        }
    }

    public function resolveFor(PaymentPanelContext $context): ?PaymentPanelProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($context)) {
                return $provider;
            }
        }
        return null;
    }

    public function resolveByProviderName(string $providerName): ?PaymentPanelProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->getProviderName() === $providerName) {
                return $provider;
            }
        }
        return null;
    }
}
