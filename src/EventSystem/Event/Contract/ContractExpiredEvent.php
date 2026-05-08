<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Event\Contract;

use OxidEsales\PaymentBase\EventSystem\Event\EventContextInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

readonly class ContractExpiredEvent implements ContractExpiredEventInterface
{
    public function __construct(
        private PaymentContractInterface $contract,
        private EventContextInterface $context,
        private int $expirationTime
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

    public function getExpirationTime(): int
    {
        return $this->expirationTime;
    }
}
