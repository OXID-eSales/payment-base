<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Handler;

use DomainException;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractReadyToCommitEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Payment\PaymentAuthorizedEvent;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\FileLoggerInterface;
use OxidEsales\PaymentBase\Service\OrderPaymentStateServiceInterface;

/**
 * Handles PaymentAuthorizedEvent from payment providers.
 *
 * This handler:
 * 1. Refuses a DRAFT contract: it has no order yet (STRP-182)
 * 2. Fulfills the PAYMENT_AUTHORIZED condition
 * 3. If all conditions met → dispatches ContractReadyToCommitEvent
 *
 * This is the bridge between provider-specific payment confirmation
 * (PaymentAuthorizedEvent) and the contract state machine.
 *
 * @since 1.0.0
 */
class PaymentAuthorizedEventHandler implements HandlerInterface
{
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly OrderPaymentStateServiceInterface $orderPaymentStateService,
        private readonly ?FileLoggerInterface $eventLogger = null
    ) {
    }

    public static function getHandledEventClass(): string
    {
        return PaymentAuthorizedEvent::class;
    }

    public function handle(object $event): void
    {
        $this->logEvent('PaymentAuthorizedEventHandler::handle() START');

        if (!$event instanceof PaymentAuthorizedEvent) {
            $this->logEvent('PaymentAuthorizedEventHandler: Wrong event type, skipping');
            return;
        }

        $context = $event->getContext();
        $contract = $context->getContract();

        if ($contract === null) {
            $this->logEvent('PaymentAuthorizedEventHandler: No contract in context');
            return;
        }

        $this->refuseADraft($contract);

        $this->logEvent('PaymentAuthorizedEventHandler: Contract found', [
            'contractId' => $contract->getId(),
            'state' => $contract->getStateValue(),
        ]);

        // Store authorization data in context for downstream handlers
        $context->set('authorizationId', $event->getAuthorizationId());
        $context->set('providerOrderId', $event->getProviderOrderId());
        $context->set('amount', $event->getAmount());
        $context->set('currency', $event->getCurrency());

        // Fulfill the payment_authorized condition
        if ($contract->getState()->isPending()) {
            $this->logEvent('PaymentAuthorizedEventHandler: Fulfilling payment_authorized condition');
            $contract->fulfillCondition(
                ContractCondition::TYPE_PAYMENT_AUTHORIZED,
                [
                    'authorizationId' => $event->getAuthorizationId(),
                    'providerOrderId' => $event->getProviderOrderId(),
                    'amount' => $event->getAmount(),
                    'currency' => $event->getCurrency(),
                ]
            );

            // Set provider info. The provider identity is owned by the
            // caller and travels via the context; this agnostic handler must
            // never guess it. When it is absent we leave the contract's
            // existing provider untouched (a provider-specific handler set it
            // earlier in the flow) and log for diagnosis.
            $providerName = $context->get('providerName');
            if (is_string($providerName) && $providerName !== '') {
                $contract->setProvider($providerName, $event->getProviderOrderId());
            } else {
                $this->logEvent(
                    'PaymentAuthorizedEventHandler: providerName absent from context — '
                    . 'leaving existing contract provider unchanged',
                    ['contractId' => $contract->getId()]
                );
            }

            // STRP-74: Update order's OXTRANSID if order was created early
            // This links the Payment Intent ID to the existing order so webhooks can find it
            $orderId = $contract->getOrderId();
            if ($orderId !== null) {
                $this->logEvent('PaymentAuthorizedEventHandler: Updating order OXTRANSID', [
                    'orderId' => $orderId,
                    'transactionId' => $event->getProviderOrderId(),
                ]);
                $this->orderPaymentStateService->updateTransactionId($orderId, $event->getProviderOrderId());
            }
        }

        // Save contract state
        $this->contractRepository->save($contract);
        $this->logEvent('PaymentAuthorizedEventHandler: Contract saved', [
            'state' => $contract->getStateValue(),
            'isReadyToCommit' => $contract->getState()->isReadyToCommit(),
            'areAllConditionsFulfilled' => $contract->areAllConditionsFulfilled(),
        ]);

        // If contract is now ready to commit, dispatch event
        if ($contract->getState()->isReadyToCommit()) {
            $this->logEvent('PaymentAuthorizedEventHandler: Dispatching ContractReadyToCommitEvent');
            $readyEvent = new ContractReadyToCommitEvent(
                $contract,
                $context,
                []
            );

            $this->eventDispatcher->dispatch($readyEvent);
            $this->logEvent('PaymentAuthorizedEventHandler: ContractReadyToCommitEvent dispatched', [
                'orderId' => $context->get('orderId'),
            ]);
        } else {
            $this->logEvent('PaymentAuthorizedEventHandler: Contract NOT ready to commit');
        }

        $this->logEvent('PaymentAuthorizedEventHandler::handle() END');
    }

    /**
     * STRP-182: a contract gets its order and moves to PENDING only through EarlyOrderCreationHandler, before the
     * shopper leaves for the provider. A confirmation that finds a DRAFT has no order to book the payment on;
     * the return leg and the webhook leg refuse it before they dispatch, so this is the invariant behind them.
     */
    private function refuseADraft(PaymentContractInterface $contract): void
    {
        if (!$contract->getState()->isDraft()) {
            return;
        }

        throw new DomainException(sprintf(
            'Payment authorization refused: contract %s is a draft and has no order',
            (string) $contract->getId()
        ));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logEvent(string $message, array $context = []): void
    {
        if ($this->eventLogger !== null) {
            $this->eventLogger->log($message, $context);
        }
    }
}
