<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem\Handler;

use DomainException;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContextInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\WebhookReceivedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractFulfilledEvent;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Repository\OrderRepositoryInterface;

/**
 * Handles contract fulfillment when payment confirmation webhook is received.
 *
 * Transitions contract to FULFILLED state and marks associated order as completed.
 *
 * @since 1.0.0
 */
class ContractFulfillmentHandler extends AbstractHandler
{
    private const FULFILLMENT_EVENT_TYPES = [
        'payment_intent.succeeded',
        'charge.succeeded',
    ];

    public function __construct(
        ContractRepositoryInterface $contractRepository,
        private OrderRepositoryInterface $orderRepository,
        ?EventDispatcherInterface $eventDispatcher = null
    ) {
        parent::__construct($contractRepository, $eventDispatcher);
    }

    public static function getHandledEventClass(): string
    {
        return WebhookReceivedEvent::class;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof WebhookReceivedEvent || !$this->isFulfillmentEvent($event)) {
            return;
        }

        $contract = $this->retrieveValidContract($event->getContext());
        if (!$contract) {
            return;
        }

        $this->fulfillContract($contract);
        $this->completeOrder($contract->getOrderId());
        $this->dispatchFulfilledEvent($contract, $event->getContext());
    }

    private function retrieveValidContract(EventContextInterface $context): ?PaymentContractInterface
    {
        $contractId = $context->get('contractId');
        if (!is_string($contractId) || $contractId === '') {
            return null;
        }

        $contract = $this->contractRepository->findById($contractId);
        if (!$contract) {
            return null;
        }

        if (!$contract->getState()->isCommitted()) {
            throw new DomainException('Contract must be COMMITTED before fulfillment');
        }

        return $contract;
    }

    private function fulfillContract(PaymentContractInterface $contract): void
    {
        $contract->fulfill();
        $this->contractRepository->save($contract);
    }

    private function completeOrder(?string $orderId): void
    {
        if (!$orderId) {
            return;
        }

        $order = $this->orderRepository->findById((int) $orderId);
        if ($order && method_exists($order, 'setStatus')) {
            $order->setStatus('completed');
            $this->orderRepository->save($order);
        }
    }

    private function dispatchFulfilledEvent(PaymentContractInterface $contract, EventContextInterface $context): void
    {
        if (!$this->eventDispatcher) {
            return;
        }

        $fulfilledEvent = new ContractFulfilledEvent(
            $contract,
            $context,
            $contract->getOrderId() ?? ''
        );

        $this->eventDispatcher->dispatch($fulfilledEvent);
    }

    private function isFulfillmentEvent(WebhookReceivedEvent $event): bool
    {
        return in_array($event->getEventType(), self::FULFILLMENT_EVENT_TYPES, true);
    }
}
