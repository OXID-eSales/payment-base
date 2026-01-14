<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem\Handler;

use DomainException;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractReadyToCommitEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractCommittedEvent;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\Order\Order;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Repository\OrderRepositoryInterface;

/**
 * Handles order creation when contract becomes ready to commit.
 *
 * Creates an order from the contract's basket snapshot and transitions
 * the contract to COMMITTED state.
 *
 * @since 1.0.0
 */
class OrderCreationHandler extends AbstractHandler
{
    public function __construct(
        ContractRepositoryInterface $contractRepository,
        private OrderRepositoryInterface $orderRepository,
        ?EventDispatcherInterface $eventDispatcher = null
    ) {
        parent::__construct($contractRepository, $eventDispatcher);
    }

    public static function getHandledEventClass(): string
    {
        return ContractReadyToCommitEvent::class;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof ContractReadyToCommitEvent) {
            return;
        }
        $contract = $event->getContract();

        if (!$contract->getState()->isReadyToCommit()) {
            throw new DomainException('Cannot create order: contract is not ready to commit');
        }

        if (!$contract->areAllConditionsFulfilled()) {
            throw new DomainException('Cannot create order: not all conditions fulfilled');
        }

        $basket = $contract->getBasketSnapshot();
        $orderId = $this->orderRepository->generateNextId();
        $orderNumber = $this->orderRepository->generateNextOrderNumber();

        $order = new Order(
            $orderId,
            $orderNumber,
            $contract->getUserId(),
            $basket->getTotalGross(),
            $basket->getTotalNet(),
            $basket->getTotalVat(),
            $basket->getCurrency(),
            $basket->getItems(),
            $contract->getId() ?? ''
        );

        $this->orderRepository->save($order);

        $contract->commitToOrder((string) $orderId);
        $this->contractRepository->save($contract);

        $committedEvent = new ContractCommittedEvent(
            $contract,
            $event->getContext(),
            (string) $orderId
        );

        if ($this->eventDispatcher) {
            $this->eventDispatcher->dispatch($committedEvent);
        }
    }
}
