<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem\Event\Contract;

use OxidEsales\PaymentComponent\EventSystem\Event\EventContextInterface;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\Contract\ContractCondition;

readonly class ContractTransitionedToPendingEvent implements ContractTransitionedToPendingEventInterface
{
    /**
     * @param array<int, ContractCondition> $conditions
     */
    public function __construct(
        private PaymentContractInterface $contract,
        private EventContextInterface $context,
        private array $conditions
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

    /**
     * @return array<int, ContractCondition>
     */
    public function getConditions(): array
    {
        return $this->conditions;
    }
}
