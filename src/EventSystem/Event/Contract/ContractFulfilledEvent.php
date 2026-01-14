<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem\Event\Contract;

use OxidEsales\PaymentComponent\EventSystem\Event\EventContextInterface;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;

readonly class ContractFulfilledEvent implements ContractFulfilledEventInterface
{
    public function __construct(
        private PaymentContractInterface $contract,
        private EventContextInterface $context,
        private string $orderId
    ) {
    }

    public function getContract(): PaymentContractInterface
    {
        return $this->contract;
    }

    public function getContext(): EventContextInterface
    {
        return $this->context;
    }

    public function getContractId(): string
    {
        return $this->contract->getId() ?? '';
    }

    public function getContractState(): string
    {
        return $this->contract->getStateValue();
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }
}
