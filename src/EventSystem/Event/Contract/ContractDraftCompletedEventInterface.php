<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Event\Contract;

use OxidEsales\PaymentBase\Contract\BasketSnapshot;

/**
 * Interface for ContractDraftCompletedEvent.
 *
 * Emitted when a contract draft is complete and ready for order creation.
 * This is the trigger for early order creation in the new flow:
 * DRAFT -> NOT_FINISHED -> PENDING
 *
 * @since 1.0.0 STRP-74
 */
interface ContractDraftCompletedEventInterface extends ContractEventInterface
{
    /**
     * Get the basket snapshot from the contract.
     */
    public function getBasketSnapshot(): BasketSnapshot;

    /**
     * Get the contract ID.
     */
    public function getContractId(): string;

    /**
     * Get the current contract state.
     */
    public function getContractState(): string;
}
