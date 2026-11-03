<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\EventSystem\Fixture;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractCreatedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\EventContextInterface;
use OxidEsales\PaymentBase\EventSystem\Handler\ContractCreationHandler;

/**
 * The smallest provider subclass of the abstract ContractCreationHandler, the
 * way the Stripe, Mollie and PayPal handlers extend it.
 */
final class FixtureContractCreationHandler extends ContractCreationHandler
{
    public static function getHandledEventClass(): string
    {
        return ContextOnlyEvent::class;
    }

    protected function dispatchContractEvent(
        PaymentContractInterface $contract,
        EventContextInterface $context
    ): void {
        $this->eventDispatcher->dispatch(new ContractCreatedEvent($contract, $context));
    }
}
