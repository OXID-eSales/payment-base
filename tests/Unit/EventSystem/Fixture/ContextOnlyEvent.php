<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\EventSystem\Fixture;

use OxidEsales\PaymentBase\EventSystem\Event\EventContextInterface;

/**
 * A provider's "checkout started" event, reduced to what the shared event
 * plumbing reads: its context. Stands in for the providers' own events
 * (Stripe, Mollie, PayPal) in payment-base's tests.
 */
final class ContextOnlyEvent
{
    public function __construct(
        private readonly EventContextInterface $context
    ) {
    }

    public function getContext(): EventContextInterface
    {
        return $this->context;
    }
}
