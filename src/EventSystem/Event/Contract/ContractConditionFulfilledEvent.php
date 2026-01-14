<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem\Event\Contract;

use OxidEsales\PaymentComponent\EventSystem\Event\EventContextInterface;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;

readonly class ContractConditionFulfilledEvent implements ContractConditionFulfilledEventInterface
{
    /**
     * @param array<string, mixed> $conditionData
     */
    public function __construct(
        private PaymentContractInterface $contract,
        private EventContextInterface $context,
        private string $conditionType,
        private array $conditionData
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

    public function getConditionType(): string
    {
        return $this->conditionType;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConditionData(): array
    {
        return $this->conditionData;
    }
}
