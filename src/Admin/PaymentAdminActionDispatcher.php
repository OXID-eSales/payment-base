<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Admin;

use OxidEsales\PaymentComponent\Admin\Contract\PaymentPanelRegistryInterface;
use OxidEsales\PaymentComponent\Admin\Panel\PaymentPanelContext;
use OxidEsales\PaymentComponent\Admin\Panel\UnsupportedPaymentActionException;

/**
 * Routes admin actions (refund, capture, cancel, …) from the shared
 * admin tab to the active panel provider. The provider translates
 * into its PSP-specific event and dispatches through the broker.
 *
 * The controller validates CSRF before calling this dispatcher.
 */
final class PaymentAdminActionDispatcher
{
    public function __construct(private readonly PaymentPanelRegistryInterface $registry)
    {
    }

    /**
     * @param array<string, mixed> $request
     */
    public function dispatch(string $action, array $request, PaymentPanelContext $context): void
    {
        $provider = $this->registry->resolveFor($context);
        if ($provider === null) {
            throw new UnsupportedPaymentActionException(sprintf(
                'No panel provider supports order "%s" (paymentType=%s). Action "%s" rejected.',
                $context->orderId,
                $context->paymentType,
                $action,
            ));
        }

        $provider->handleAction($action, $request, $context);
    }
}
