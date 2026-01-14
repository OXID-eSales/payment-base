<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem\Handler;

use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractCreatedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractDraftCompletedEvent;

/**
 * Handles contract creation and dispatches draft completed event.
 *
 * When a contract is created, this handler validates conditions are set
 * and dispatches ContractDraftCompletedEvent to trigger the order creation
 * flow: DRAFT -> NOT_FINISHED -> PENDING.
 *
 * STRP-74: Updated to dispatch ContractDraftCompletedEvent instead of
 * transitioning directly to PENDING. The EarlyOrderCreationHandler
 * will handle order creation and state transitions.
 *
 * @since 1.0.0
 */
class ContractConditionResolverHandler extends AbstractHandler
{
    public static function getHandledEventClass(): string
    {
        return ContractCreatedEvent::class;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof ContractCreatedEvent) {
            return;
        }

        $contract = $event->getContract();

        if (!$contract instanceof PaymentContract) {
            return;
        }

        if (empty($contract->getConditions())) {
            throw new \DomainException('Cannot transition to PENDING without conditions');
        }

        if ($this->eventDispatcher === null) {
            return;
        }

        // Dispatch event to trigger EarlyOrderCreationHandler
        // which will create order and transition DRAFT -> NOT_FINISHED -> PENDING
        $draftCompletedEvent = new ContractDraftCompletedEvent(
            $contract,
            $event->getContext()
        );

        $this->eventDispatcher->dispatch($draftCompletedEvent);
    }
}
