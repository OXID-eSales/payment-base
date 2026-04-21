<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem\Broker;

use OxidEsales\PaymentComponent\EventSystem\Event\EventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\Request\AbstractProviderRequestEvent;

/**
 * One implementation per payment provider. Declares which `providerName` the
 * translator supports and maps an abstract request event to the provider's
 * own concrete event class.
 *
 * Tag in services.yaml: `{ name: oe.payment.event_translator }`. The broker's
 * compiler pass collects every tagged service at container compile time.
 */
interface ProviderEventTranslatorInterface
{
    public function supports(string $providerName): bool;

    /**
     * Return the provider-specific event to dispatch, or null if the translator
     * has no mapping for this request type (broker then no-ops and logs).
     */
    public function translate(AbstractProviderRequestEvent $event): ?EventInterface;
}
