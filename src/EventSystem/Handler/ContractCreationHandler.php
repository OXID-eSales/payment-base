<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem\Handler;

use InvalidArgumentException;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\PaymentInitiatedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractCreatedEvent;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\Service\ContractServiceInterface;

class ContractCreationHandler implements HandlerInterface
{
    public function __construct(
        private ContractServiceInterface $contractService,
        private EventDispatcherInterface $eventDispatcher
    ) {
    }

    public static function getHandledEventClass(): string
    {
        return PaymentInitiatedEvent::class;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof PaymentInitiatedEvent) {
            return;
        }

        $context = $event->getContext();

        $userId = $context->get('userId');
        if (!is_string($userId) || $userId === '') {
            throw new InvalidArgumentException('User ID is required');
        }

        $basket = $context->get('basket');
        if (!is_object($basket)) {
            throw new InvalidArgumentException('Basket is required');
        }

        $conditionTypes = $context->get('conditionTypes', []);
        if (!is_array($conditionTypes)) {
            $conditionTypes = [];
        }

        /** @var array<int, string> $validatedConditionTypes */
        $validatedConditionTypes = array_values(array_filter($conditionTypes, 'is_string'));

        $contract = $this->contractService->createContract(
            $userId,
            $basket,
            $validatedConditionTypes
        );

        $context->setContract($contract);

        $contractCreatedEvent = new ContractCreatedEvent($contract, $context);
        $this->eventDispatcher->dispatch($contractCreatedEvent);
    }
}
