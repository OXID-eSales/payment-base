<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem\Handler;

use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractTransitionedToPendingEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractReadyToCommitEvent;
use OxidEsales\PaymentComponent\Contract\ContractCondition;

/**
 * Handles payment authorization events.
 *
 * When a contract transitions to PENDING state (payment authorized),
 * this handler fulfills the PAYMENT_AUTHORIZED condition and checks
 * if all conditions are met to transition to READY_TO_COMMIT.
 *
 * @since 1.0.0
 */
class PaymentAuthorizationHandler extends AbstractHandler
{
    public static function getHandledEventClass(): string
    {
        return ContractTransitionedToPendingEvent::class;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof ContractTransitionedToPendingEvent) {
            return;
        }
        $contract = $event->getContract();
        $context = $event->getContext();

        $authorizationId = $context->get('authorizationId');
        $providerOrderId = $context->get('providerOrderId');

        $contract->fulfillCondition(
            ContractCondition::TYPE_PAYMENT_AUTHORIZED,
            [
                'authorizationId' => $authorizationId,
                'providerOrderId' => $providerOrderId,
            ]
        );

        // Set provider info from context (provider name is set by provider-specific handlers)
        $providerName = $context->get('providerName');
        if (is_string($providerOrderId) && is_string($providerName)) {
            $contract->setProvider($providerName, $providerOrderId);
        }

        $this->contractRepository->save($contract);

        if ($contract->areAllConditionsFulfilled() && $this->eventDispatcher !== null) {
            $readyEvent = new ContractReadyToCommitEvent(
                $contract,
                $context,
                []
            );

            $this->eventDispatcher->dispatch($readyEvent);
        }
    }
}
