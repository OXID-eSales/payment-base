<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Service;

use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;

/**
 * Interface for contract fulfillment operations.
 *
 * SOLID Principles:
 * - SRP: Single responsibility - contract fulfillment
 * - ISP: Focused interface with fulfillment methods only
 * - DIP: Handlers depend on this abstraction
 *
 * DRY: Consolidates all contract fulfillment operations that were
 * previously scattered across 8+ locations with duplicate logic.
 *
 * @since 1.0.0
 */
interface ContractFulfillmentServiceInterface
{
    /**
     * Fulfill a contract.
     *
     * Guards:
     * - Contract must be in COMMITTED state
     * - Contract must not already be fulfilled
     *
     * Actions:
     * - Transitions contract to FULFILLED state
     * - Persists the contract
     * - Dispatches ContractFulfilledEvent
     *
     * @param PaymentContractInterface $contract Contract to fulfill
     * @return bool True if fulfilled, false if guards failed
     */
    public function fulfill(PaymentContractInterface $contract): bool;

    /**
     * Fulfill contract by provider order ID.
     *
     * Looks up contract by provider order ID (e.g., Stripe PaymentIntent ID)
     * and fulfills it if found.
     *
     * @param string $providerOrderId Provider's order/transaction ID
     * @return bool|null True if fulfilled, false if guards failed, null if not found
     */
    public function fulfillByProviderOrderId(string $providerOrderId): ?bool;

    /**
     * Fulfill contract by contract ID.
     *
     * Looks up contract by its internal OXID and fulfills it if found.
     *
     * @param string $contractId Contract OXID
     * @return bool|null True if fulfilled, false if guards failed, null if not found
     */
    public function fulfillByContractId(string $contractId): ?bool;
}
