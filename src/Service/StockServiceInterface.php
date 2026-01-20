<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Service;

use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\Service\Exception\InsufficientStockException;
use OxidEsales\PaymentComponent\Service\Exception\StockReleaseException;

/**
 * Contract-aware interface for stock operations.
 *
 * Sprint 2: Manages stock reservation and release at the contract level.
 * Implementations manipulate OXARTICLES.OXSTOCK directly (no tracking table).
 *
 * Stock is reserved on contract DRAFT (before payment) and released on
 * all terminal states except FULFILLED.
 *
 * @since 1.0.0
 */
interface StockServiceInterface
{
    /**
     * Reserve stock for all items in contract's basket snapshot.
     *
     * Decrements OXARTICLES.OXSTOCK directly for each item.
     * This is a synchronous operation that must complete before Stripe redirect.
     *
     * @param PaymentContractInterface $contract Contract with basket snapshot
     * @throws InsufficientStockException If any item has insufficient stock
     */
    public function reserveForContract(PaymentContractInterface $contract): void;

    /**
     * Release reserved stock for contract.
     *
     * Increments OXARTICLES.OXSTOCK directly for each item.
     * Called on all terminal states except FULFILLED.
     *
     * @param PaymentContractInterface $contract Contract to release stock for
     * @throws StockReleaseException If release fails (strict consistency)
     */
    public function releaseForContract(PaymentContractInterface $contract): void;

    /**
     * Check if all items in contract's basket have sufficient stock.
     *
     * @param PaymentContractInterface $contract Contract to check
     * @return bool True if all items available
     */
    public function hasAvailableStock(PaymentContractInterface $contract): bool;
}
