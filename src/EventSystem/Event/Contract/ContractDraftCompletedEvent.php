<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Event\Contract;

use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContextInterface;

/**
 * Event emitted when a contract draft is complete.
 *
 * This event triggers early order creation in the new flow:
 * DRAFT -> NOT_FINISHED -> PENDING
 *
 * The handler (EarlyOrderCreationHandler) will:
 * 1. Create the order from the basket snapshot
 * 2. Transition the contract to NOT_FINISHED state
 * 3. Link the contract to the order
 *
 * @since 1.0.0 STRP-74
 */
readonly class ContractDraftCompletedEvent implements ContractDraftCompletedEventInterface
{
    public function __construct(
        private PaymentContractInterface $contract,
        private EventContextInterface $context
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

    public function getBasketSnapshot(): BasketSnapshot
    {
        return $this->contract->getBasketSnapshot();
    }

    public function getContractId(): string
    {
        return $this->contract->getId() ?? '';
    }

    public function getContractState(): string
    {
        return $this->contract->getStateValue();
    }
}
