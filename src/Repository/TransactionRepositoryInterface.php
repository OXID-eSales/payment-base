<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Repository;

use OxidEsales\PaymentBase\Contract\Transaction;

/**
 * Repository interface for Transaction persistence
 *
 * Provider-agnostic transaction repository for tracking payment operations.
 */
interface TransactionRepositoryInterface
{
    /**
     * Save a transaction to the database
     */
    public function save(Transaction $transaction): void;

    /**
     * Find a transaction by its ID
     */
    public function findById(string $id): ?Transaction;

    /**
     * Find transactions by order ID
     *
     * @return Transaction[]
     */
    public function findByOrderId(string $orderId): array;

    /**
     * Find transactions by contract ID
     *
     * @return Transaction[]
     */
    public function findByContractId(string $contractId): array;

    /**
     * Find a transaction by provider transaction ID
     */
    public function findByProviderTransactionId(string $transactionId): ?Transaction;

    /**
     * Check if a transaction exists by ID
     */
    public function exists(string $id): bool;

    /**
     * Log a refund transaction
     *
     * @param string $contractId The contract ID
     * @param float $amount Refund amount
     * @param string $refundId Provider refund ID
     * @param string $reason Refund reason
     */
    public function logRefund(string $contractId, float $amount, string $refundId, string $reason): void;
}
