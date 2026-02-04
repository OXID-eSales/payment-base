<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Repository;

use OxidEsales\PaymentComponent\Contract\Transaction;

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
     * Find transactions by type and status
     *
     * @return Transaction[]
     */
    public function findByTypeAndStatus(string $type, string $status): array;

    /**
     * Find child transactions (refunds, voids) for a parent transaction
     *
     * @return Transaction[]
     */
    public function findChildTransactions(string $parentTransactionId): array;

    /**
     * Check if a transaction exists by ID
     */
    public function exists(string $id): bool;

    /**
     * Get total refunded amount for a contract
     *
     * @param string $contractId The contract ID
     * @return float Total amount refunded
     */
    public function getTotalRefundedForContract(string $contractId): float;

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
