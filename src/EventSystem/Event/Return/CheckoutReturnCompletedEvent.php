<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Event\Return;

use OxidEsales\PaymentBase\EventSystem\Event\EventContextInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventInterface;
use OxidEsales\PaymentBase\Return\ReturnResolution;

/**
 * Unified "shopper-just-came-back-from-the-PSP" event. Replaces Stripe's and
 * PayPal's individual *CheckoutReturnEvent classes (decision §9.3).
 *
 * Carries a provider-neutral {@see ReturnResolution} alongside the standard
 * EventContext. Shared handlers listen to this single event class.
 */
readonly class CheckoutReturnCompletedEvent implements EventInterface
{
    public function __construct(
        private EventContextInterface $context,
        private ReturnResolution $resolution,
    ) {
    }

    public function getContext(): EventContextInterface
    {
        return $this->context;
    }

    public function getResolution(): ReturnResolution
    {
        return $this->resolution;
    }
}
