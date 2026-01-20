<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem\Handler;

use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractCreatedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContextInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\PaymentInitiatedEvent;

/**
 * Generic contract creation handler for basic payment flows.
 *
 * Sprint 1: Concrete implementation of ContractCreationHandler.
 * This handler listens for PaymentInitiatedEvent and dispatches
 * ContractCreatedEvent after contract creation.
 *
 * Provider modules (Stripe, PayPal, etc.) should create their own
 * handlers extending ContractCreationHandler with provider-specific
 * event handling and post-creation logic.
 *
 * @since 1.0.0
 */
class GenericContractCreationHandler extends ContractCreationHandler
{
    /**
     * @inheritDoc
     */
    public static function getHandledEventClass(): string
    {
        return PaymentInitiatedEvent::class;
    }

    /**
     * @inheritDoc
     */
    protected function dispatchContractEvent(
        PaymentContractInterface $contract,
        EventContextInterface $context
    ): void {
        $event = new ContractCreatedEvent($contract, $context);
        $this->eventDispatcher->dispatch($event);
    }
}
