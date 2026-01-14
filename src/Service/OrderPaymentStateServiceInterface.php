<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Service;

use DateTimeImmutable;

/**
 * Interface for order payment state operations.
 *
 * SOLID Principles:
 * - SRP: Single responsibility - order payment state updates
 * - ISP: Focused interface with essential methods only
 * - DIP: Handlers depend on this abstraction
 *
 * DRY: Consolidates all OXPAID/OXTRANSSTATUS/OXTRANSID update operations
 * that were previously scattered across 4+ locations.
 *
 * @since 1.0.0
 */
interface OrderPaymentStateServiceInterface
{
    /**
     * Update OXPAID timestamp.
     *
     * @param string $orderId Order OXID
     * @param DateTimeImmutable|null $paidAt Timestamp (current time if null)
     * @return bool True if order was updated
     */
    public function updatePaidTimestamp(
        string $orderId,
        ?DateTimeImmutable $paidAt = null
    ): bool;

    /**
     * Update OXPAID timestamp by transaction ID (OXTRANSID).
     *
     * Used when order ID is not known but transaction ID is available.
     *
     * @param string $transactionId Provider transaction ID (e.g., PaymentIntent ID)
     * @param DateTimeImmutable|null $paidAt Timestamp (current time if null)
     * @return bool True if order was updated
     */
    public function updatePaidTimestampByTransactionId(
        string $transactionId,
        ?DateTimeImmutable $paidAt = null
    ): bool;

    /**
     * Update OXTRANSSTATUS.
     *
     * @param string $orderId Order OXID
     * @param string $status Status value ('OK', 'ERROR', 'NOT_FINISHED')
     * @return bool True if order was updated
     */
    public function updateTransactionStatus(string $orderId, string $status): bool;

    /**
     * Update OXTRANSID (transaction/payment intent ID).
     *
     * Only updates if OXTRANSID is currently empty.
     *
     * @param string $orderId Order OXID
     * @param string $transactionId Provider transaction ID
     * @return bool True if order was updated
     */
    public function updateTransactionId(string $orderId, string $transactionId): bool;

    /**
     * Mark order as paid with all relevant fields.
     *
     * Convenience method that updates in a single operation:
     * - OXPAID
     * - OXTRANSSTATUS = 'OK'
     * - OXTRANSID (if not already set)
     *
     * @param string $orderId Order OXID
     * @param string|null $transactionId Provider transaction ID
     * @param DateTimeImmutable|null $paidAt Timestamp (current time if null)
     * @return bool True if order was updated
     */
    public function markOrderAsPaid(
        string $orderId,
        ?string $transactionId = null,
        ?DateTimeImmutable $paidAt = null
    ): bool;
}
