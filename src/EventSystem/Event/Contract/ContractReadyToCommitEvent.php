<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem\Event\Contract;

use OxidEsales\PaymentComponent\EventSystem\Event\EventContextInterface;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;

readonly class ContractReadyToCommitEvent implements ContractReadyToCommitEventInterface
{
    /**
     * @param array<string, mixed> $paymentProviderData
     */
    public function __construct(
        private PaymentContractInterface $contract,
        private EventContextInterface $context,
        private array $paymentProviderData
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
     * @return array<string, mixed>
     */
    public function getPaymentProviderData(): array
    {
        return $this->paymentProviderData;
    }
}
