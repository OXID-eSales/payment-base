<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

use OxidEsales\PaymentBase\Admin\Contract\AdminActionBounds;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionBoundsProviderInterface;
use OxidEsales\PaymentBase\Admin\Contract\PaymentPanelRegistryInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Asks the panel provider of the contract for its live bounds. A provider that cannot answer is the closed bound
 * (nothing offered, writes refused): a bound that cannot be read must never be replaced by a more generous guess.
 *
 * @since 1.7.0
 */
final class PanelActionBoundsResolver implements AdminActionBoundsResolverInterface
{
    public function __construct(
        private readonly PaymentPanelRegistryInterface $panels,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function boundsFor(PaymentContractInterface $contract): ?LazyActionBounds
    {
        $panel = $this->panels->resolveByProviderName((string) $contract->getProvider());
        if (!$panel instanceof AdminActionBoundsProviderInterface) {
            return null;
        }

        return new LazyActionBounds(function () use ($panel, $contract): ?AdminActionBounds {
            try {
                return $panel->boundsFor($contract);
            } catch (Throwable $failure) {
                $this->logger->warning('Provider bounds of an admin payment could not be read: failing closed', [
                    'provider' => $contract->getProvider(),
                    'exception' => $failure::class,
                    'reason' => $failure->getMessage(),
                ]);

                return AdminActionBounds::unavailable();
            }
        });
    }
}
