<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Broker;

use OxidEsales\PaymentBase\EventSystem\Event\Request\AbstractProviderRequestEvent;

/**
 * Routes a provider-neutral `AbstractProviderRequestEvent` to the active
 * provider's concrete event (via a registered `ProviderEventTranslatorInterface`)
 * and dispatches the translated event through the standard payment-base
 * dispatcher.
 *
 * Provider resolution precedence:
 *   1. `$event->getContext()->get('providerName')` (explicit).
 *   2. `$event->getContext()->getContract()?->getProvider()` (implicit from the
 *      contract itself).
 */
interface EventBrokerInterface
{
    public function dispatch(AbstractProviderRequestEvent $event): AbstractProviderRequestEvent;
}
