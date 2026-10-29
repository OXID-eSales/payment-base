<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Handler;

use OxidEsales\PaymentBase\Adapter\Request\CreateOrderRequest;
use OxidEsales\PaymentBase\Adapter\EarlyOrderWriterInterface;
use OxidEsales\PaymentBase\Checkout\OpenCheckoutAttemptRegistryInterface;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractDraftCompletedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractTransitionedToPendingEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Payment\OrderCreatedEvent;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
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
        ?EventDispatcherInterface $eventDispatcher = null,
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

        if (!$contract instanceof PaymentContract) {
            $this->logEvent('EarlyOrderCreationHandler: Contract is not PaymentContract, skipping');
            return;
        }

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

        try {
            $orderData = $this->createOrder($contract, $event);
            $this->transitionContractToNotFinished($contract, $orderData['orderId']);
            $this->transitionContractToPending($contract, $event);
            $this->dispatchOrderCreatedEvent($event, $orderData['orderId']);
            $this->openAttempts->remember($contract->getId());
            $this->logEvent('EarlyOrderCreationHandler::handle() END - SUCCESS');
        } catch (\Throwable $e) {
            $this->logEvent('EarlyOrderCreationHandler: Order creation failed', [
                'contract_id' => $contract->getId(),
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);
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
     * @return array{orderId: string, orderNumber: string}
     */
    private function createOrder(PaymentContract $contract, ContractDraftCompletedEvent $event): array
    {
        $basket = $event->getBasketSnapshot();
        $context = $event->getContext();

        $contextPaymentId = $context->get('paymentId');
        $paymentId = is_string($contextPaymentId) ? $contextPaymentId : 'unknown_payment';
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
                'contract_id' => $contract->getId(),
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

    private function transitionContractToNotFinished(PaymentContract $contract, string $orderId): void
    {
        $this->logEvent('EarlyOrderCreationHandler: Transitioning to NOT_FINISHED', [
            'contractId' => $contract->getId(),
            'orderId' => $orderId,
        ]);

        $contract->transitionToNotFinished($orderId);
        $this->contractRepository->save($contract);
    }

    private function transitionContractToPending(PaymentContract $contract, ContractDraftCompletedEvent $event): void
    {
        $this->logEvent('EarlyOrderCreationHandler: Transitioning to PENDING', [
            'contractId' => $contract->getId(),
        ]);

        $contract->transitionToPending();
        $this->contractRepository->save($contract);

        $dispatcher = $this->eventDispatcher;
        if ($dispatcher === null) {
            return;
        }

        $pendingEvent = new ContractTransitionedToPendingEvent(
            $contract,
            $event->getContext(),
            $contract->getConditions()
        );

        $this->logEvent('EarlyOrderCreationHandler: Dispatching ContractTransitionedToPendingEvent', [
            'contractId' => $contract->getId(),
        ]);

        $dispatcher->dispatch($pendingEvent);
    }

    private function dispatchOrderCreatedEvent(ContractDraftCompletedEvent $event, string $orderId): void
    {
        $dispatcher = $this->eventDispatcher;
        if ($dispatcher === null) {
            return;
        }

        $orderCreatedEvent = new OrderCreatedEvent(
            $event->getContext(),
            $orderId,
            $event->getContractId()
        );

        $this->logEvent('EarlyOrderCreationHandler: Dispatching OrderCreatedEvent', [
            'orderId' => $orderId,
            'contractId' => $event->getContractId(),
        ]);

        $dispatcher->dispatch($orderCreatedEvent);
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
