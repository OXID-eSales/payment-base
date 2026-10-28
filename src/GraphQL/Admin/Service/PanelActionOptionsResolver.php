<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

use OxidEsales\PaymentBase\Admin\Contract\AdminActionOptions;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionOptionsProviderInterface;
use OxidEsales\PaymentBase\Admin\Contract\PaymentPanelRegistryInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Asks the panel provider of the contract (the one the classic Payment tab uses) for its action options. The
 * options help the screen and pre-check input; the provider validates again, so a provider that cannot answer does
 * not fail the read - it is treated as free text and logged.
 *
 * @since 1.7.0
 */
final class PanelActionOptionsResolver implements AdminActionOptionsResolverInterface
{
    public function __construct(
        private readonly PaymentPanelRegistryInterface $panels,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function optionsFor(PaymentContractInterface $contract): AdminActionOptions
    {
        $panel = $this->panels->resolveByProviderName((string) $contract->getProvider());
        if (!$panel instanceof AdminActionOptionsProviderInterface) {
            return AdminActionOptions::freeText();
        }
        try {
            return $panel->actionOptions($contract);
        } catch (Throwable $failure) {
            $this->logger->warning('Provider could not declare its admin action options', [
                'provider' => $contract->getProvider(),
                'exception' => $failure::class,
            ]);

            return AdminActionOptions::freeText();
        }
    }
}
