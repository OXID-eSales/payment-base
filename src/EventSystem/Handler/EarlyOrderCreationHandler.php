<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Handler;

use OxidEsales\PaymentBase\Adapter\Request\CreateOrderRequest;
use OxidEsales\PaymentBase\Adapter\EarlyOrderWriterInterface;
use OxidEsales\PaymentBase\Checkout\Attempt\OrderCreationAlreadyClaimedException;
use OxidEsales\PaymentBase\Checkout\Attempt\OrderCreationClaimInterface;
use OxidEsales\PaymentBase\Checkout\OpenCheckoutAttemptRegistryInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use InvalidArgumentException;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractDraftCompletedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\EventContextInterface;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractTransitionedToPendingEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Payment\OrderCreatedEvent;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\Logging\ThrowableSummaryInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\FileLoggerInterface;

/**
 * Handles early order creation when contract draft is completed.
 *
 * This handler implements the new flow for STRP-74:
 * DRAFT -> NOT_FINISHED -> PENDING
 *
 * When a ContractDraftCompletedEvent is received, this handler:
 * 1. Creates an order via ShopOrderService
 * 2. Transitions the contract to NOT_FINISHED state
 * 3. Links the contract to the order
 * 4. Dispatches OrderCreatedEvent
 *
 * SOLID Principles:
 * - SRP: Single responsibility - create order when contract draft is complete
 * - OCP: Extends AbstractHandler without modification
 * - LSP: Fully substitutable for AbstractHandler
 * - ISP: Implements only required methods
 * - DIP: Depends on abstractions (interfaces)
 *
 * @since 1.0.0 STRP-74
 */
class EarlyOrderCreationHandler extends AbstractHandler
{
    public function __construct(
        ContractRepositoryInterface $contractRepository,
        // OPC-175: creates the order of a new contract, rewrites the order of
        // a contract that continues the open attempt (one order, one contract).
        private readonly EarlyOrderWriterInterface $earlyOrders,
        // The session's open attempt: the next start continues it.
        private readonly OpenCheckoutAttemptRegistryInterface $openAttempts,
        EventDispatcherInterface $eventDispatcher,
        private readonly ThrowableSummaryInterface $throwableSummary,
        // MOL-39: requests that loaded the same copy of the contract (also from
        // different sessions) race for the order; one creates it.
        private readonly OrderCreationClaimInterface $orderCreationClaims,
        private readonly ?FileLoggerInterface $eventLogger = null
    ) {
        parent::__construct($contractRepository, $eventDispatcher);
    }

    public static function getHandledEventClass(): string
    {
        return ContractDraftCompletedEvent::class;
    }

    public function handle(object $event): void
    {
        $this->logEvent('EarlyOrderCreationHandler::handle() START');

        if (!$event instanceof ContractDraftCompletedEvent) {
            $this->logEvent('EarlyOrderCreationHandler: Wrong event type, skipping');
            return;
        }

        $contract = $event->getContract();

        if (!$contract->getState()->isDraft()) {
            $this->logEvent('EarlyOrderCreationHandler: Contract not in DRAFT state, skipping', [
                'contractId' => $contract->getId(),
                'state' => $contract->getStateValue(),
            ]);
            return;
        }

        $this->logEvent('EarlyOrderCreationHandler: Processing', [
            'contractId' => $contract->getId(),
            'state' => $contract->getStateValue(),
        ]);

        // MOL-39 / OPC-385: the session challenge (MOL-18) is per session and the row version (MOL-17)
        // is checked on save, after the order exists. The claim comes first: the request that loses it
        // creates nothing - no order, no stock, no mail - and says so.
        if (!$this->orderCreationClaims->claim($contract)) {
            $this->logEvent('EarlyOrderCreationHandler: order creation already claimed by a concurrent request', [
                'contractId' => $contract->getId(),
            ]);
            throw OrderCreationAlreadyClaimedException::forContract((string) $contract->getId());
        }

        try {
            $orderData = $this->createOrder($contract, $event);
            $this->transitionContractToNotFinished($contract, $orderData['orderId']);
            $this->transitionContractToPending($contract, $event);
            $this->dispatchOrderCreatedEvent($event, $orderData['orderId']);
            $this->openAttempts->remember($event->getContractId());
            $this->logEvent('EarlyOrderCreationHandler::handle() END - SUCCESS');
        } catch (\Throwable $e) {
            $this->logEvent('EarlyOrderCreationHandler: Order creation failed', [
                'contract_id' => $contract->getId(),
                'throwable' => $this->throwableSummary->fromThrowable($e),
            ]);
            // The shopper's next attempt for this contract must not wait for the lease.
            $this->orderCreationClaims->release($contract);
            throw $e;
        }
    }

    /**
     * Sprint 15 / S1: a headless checkout names the persisted basket it pays
     * for; the Twig checkout has none here and keeps the session basket.
     */
    private function basketIdOf(ContractDraftCompletedEvent $event): ?string
    {
        $contextBasketId = $event->getContext()->get('basketId');

        return is_string($contextBasketId) && $contextBasketId !== '' ? $contextBasketId : null;
    }

    /**
     * An order names the payment it is paid with. A context without it is a
     * wiring error of the caller, never a reason to invent an id (VR-26 A2).
     */
    private function paymentIdOf(EventContextInterface $context): string
    {
        $paymentId = $context->get('paymentId');
        if (!is_string($paymentId) || $paymentId === '') {
            throw new InvalidArgumentException('Cannot create the order: the event context carries no payment id');
        }

        return $paymentId;
    }

    /**
     * @return array{orderId: string, orderNumber: string}
     */
    private function createOrder(PaymentContractInterface $contract, ContractDraftCompletedEvent $event): array
    {
        $basket = $event->getBasketSnapshot();
        $context = $event->getContext();

        $paymentId = $this->paymentIdOf($context);
        $sessionId = (string) $context->get('sessionId', 'contract_' . $contract->getId());
        $basketId = $this->basketIdOf($event);
        if ($basketId !== null) {
            // Sprint 15 / S3: so the next attempt for this basket, and the
            // basket removal on commit, can find this contract without a session.
            $contract->setMetadata('basket_id', $basketId);
        }

        $this->logEvent('EarlyOrderCreationHandler: Creating order', [
            'userId' => $contract->getUserId(),
            'paymentId' => $paymentId,
            'totalGross' => $basket->getTotalGross(),
            'sessionId' => $sessionId,
            'basketId' => $basketId,
        ]);

        $request = new CreateOrderRequest(
            sessionId: $sessionId,
            userId: $contract->getUserId(),
            paymentId: $paymentId,
            paymentTransactionId: null,
            orderRemark: null,
            metadata: [
                'contract_id' => $event->getContractId(),
            ],
            initialStatus: 'NOT_FINISHED',
            basketId: $basketId
        );

        $orderResponse = $this->earlyOrders->write($contract, $request);

        $this->logEvent('EarlyOrderCreationHandler: Order created', [
            'orderId' => $orderResponse->orderId,
            'orderNumber' => $orderResponse->orderNumber,
            'contract_id' => $contract->getId(),
        ]);

        // STRP-75: Store order number in contract metadata for later use
        $contract->setMetadata('order_number', (string) $orderResponse->orderNumber);

        return [
            'orderId' => $orderResponse->orderId,
            'orderNumber' => (string) $orderResponse->orderNumber,
        ];
    }

    private function transitionContractToNotFinished(PaymentContractInterface $contract, string $orderId): void
    {
        $this->logEvent('EarlyOrderCreationHandler: Transitioning to NOT_FINISHED', [
            'contractId' => $contract->getId(),
            'orderId' => $orderId,
        ]);

        $contract->transitionToNotFinished($orderId);
        $this->contractRepository->save($contract);
    }

    private function transitionContractToPending(PaymentContractInterface $contract, ContractDraftCompletedEvent $event): void
    {
        $this->logEvent('EarlyOrderCreationHandler: Transitioning to PENDING', [
            'contractId' => $contract->getId(),
        ]);

        $contract->transitionToPending();
        $this->contractRepository->save($contract);

        $pendingEvent = new ContractTransitionedToPendingEvent(
            $contract,
            $event->getContext(),
            $contract->getConditions()
        );

        $this->logEvent('EarlyOrderCreationHandler: Dispatching ContractTransitionedToPendingEvent', [
            'contractId' => $contract->getId(),
        ]);

        $this->eventDispatcher->dispatch($pendingEvent);
    }

    private function dispatchOrderCreatedEvent(ContractDraftCompletedEvent $event, string $orderId): void
    {
        $orderCreatedEvent = new OrderCreatedEvent(
            $event->getContext(),
            $orderId,
            $event->getContractId()
        );

        $this->logEvent('EarlyOrderCreationHandler: Dispatching OrderCreatedEvent', [
            'orderId' => $orderId,
            'contractId' => $event->getContractId(),
        ]);

        $this->eventDispatcher->dispatch($orderCreatedEvent);
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
