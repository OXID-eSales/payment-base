<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

use OxidEsales\PaymentBase\Admin\Contract\PaymentPanelRegistryInterface;
use OxidEsales\PaymentBase\Admin\Panel\AdminActionRefusedException;
use OxidEsales\PaymentBase\Admin\Panel\PaymentPanelContext;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs an admin payment action through the panel provider that the provider modules register for the classic
 * Payment tab (`oe.payment.admin_panel`): the capture, refund and cancel code of each module is reused as it is,
 * with the same amount limits it applies for the classic tab. The provider validates and reports through its
 * own channels; what it achieved is read back from the contract by the caller.
 *
 * @since 1.5.0
 */
final class PanelProviderActionRunner implements ProviderActionRunnerInterface
{
    public function __construct(
        private readonly PaymentPanelRegistryInterface $panels,
        private readonly ContractRepositoryInterface $contracts,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(string $provider, string $orderId, string $action, array $request): ProviderActionOutcome
    {
        $panel = $this->panels->resolveByProviderName($provider);
        $contract = $this->contracts->findByOrderId($orderId);
        if ($panel === null || $contract === null) {
            return ProviderActionOutcome::unavailable();
        }
        try {
            $panel->handleAction($action, $request, new PaymentPanelContext($orderId, '', $contract));
        } catch (AdminActionRefusedException $refusal) {
            $this->logger->info('Admin payment action refused by the provider', [
                'orderId' => $orderId,
                'provider' => $provider,
                'action' => $action,
                'refusalCode' => $refusal->refusalCode(),
            ]);

            return ProviderActionOutcome::refused($refusal->refusalCode(), $refusal->getMessage());
        } catch (Throwable $failure) {
            $this->logger->error('Admin payment action failed at the provider', [
                'orderId' => $orderId,
                'provider' => $provider,
                'action' => $action,
                'exception' => $failure::class,
                'reason' => $failure->getMessage(),
            ]);
            return ProviderActionOutcome::failed();
        }

        return ProviderActionOutcome::ran();
    }
}
