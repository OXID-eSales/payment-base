<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Service for order payment state operations.
 *
 * SOLID Principles:
 * - SRP: Only handles order payment state updates
 * - OCP: Open for extension via interface
 * - DIP: Depends on Connection abstraction
 *
 * DRY: Single location for all OXPAID/OXTRANSSTATUS/OXTRANSID updates.
 * Previously this logic was duplicated in 4+ locations with inconsistent
 * date handling (PHP date vs MySQL NOW vs Stripe timestamp).
 *
 * @since 1.0.0
 */
class OrderPaymentStateService implements OrderPaymentStateServiceInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger
    ) {
    }

    public function updatePaidTimestamp(
        string $orderId,
        ?DateTimeImmutable $paidAt = null
    ): bool {
        $timestamp = $paidAt ?? new DateTimeImmutable();

        try {
            $sql = "UPDATE oxorder SET OXPAID = :paid WHERE OXID = :orderId AND OXPAID = '0000-00-00 00:00:00'";
            $affected = $this->connection->executeStatement($sql, [
                'paid' => $timestamp->format('Y-m-d H:i:s'),
                'orderId' => $orderId,
            ]);

            if ($affected > 0) {
                $this->logger->debug('OXPAID timestamp updated', [
                    'order_id' => $orderId,
                    'paid_at' => $timestamp->format('Y-m-d H:i:s'),
                ]);
            }

            return $affected > 0;
        } catch (\Exception $e) {
            $this->logger->error('Failed to update OXPAID', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function updatePaidTimestampByTransactionId(
        string $transactionId,
        ?DateTimeImmutable $paidAt = null
    ): bool {
        $timestamp = $paidAt ?? new DateTimeImmutable();

        try {
            $sql = "UPDATE oxorder SET OXPAID = :paid WHERE OXTRANSID = :transId AND OXPAID = '0000-00-00 00:00:00'";
            $affected = $this->connection->executeStatement($sql, [
                'paid' => $timestamp->format('Y-m-d H:i:s'),
                'transId' => $transactionId,
            ]);

            if ($affected > 0) {
                $this->logger->debug('OXPAID timestamp updated by transaction ID', [
                    'transaction_id' => $transactionId,
                    'paid_at' => $timestamp->format('Y-m-d H:i:s'),
                ]);
            }

            return $affected > 0;
        } catch (\Exception $e) {
            $this->logger->error('Failed to update OXPAID by transaction ID', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function updateTransactionStatus(string $orderId, string $status): bool
    {
        try {
            $sql = 'UPDATE oxorder SET OXTRANSSTATUS = :status WHERE OXID = :orderId';
            $affected = $this->connection->executeStatement($sql, [
                'status' => $status,
                'orderId' => $orderId,
            ]);

            if ($affected > 0) {
                $this->logger->debug('OXTRANSSTATUS updated', [
                    'order_id' => $orderId,
                    'status' => $status,
                ]);
            }

            return $affected > 0;
        } catch (\Exception $e) {
            $this->logger->error('Failed to update OXTRANSSTATUS', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function updateTransactionId(string $orderId, string $transactionId): bool
    {
        try {
            $sql = "UPDATE oxorder SET OXTRANSID = :transId WHERE OXID = :orderId AND (OXTRANSID IS NULL OR OXTRANSID = '')";
            $affected = $this->connection->executeStatement($sql, [
                'transId' => $transactionId,
                'orderId' => $orderId,
            ]);

            if ($affected > 0) {
                $this->logger->debug('OXTRANSID updated', [
                    'order_id' => $orderId,
                    'transaction_id' => $transactionId,
                ]);
            }

            return $affected > 0;
        } catch (\Exception $e) {
            $this->logger->error('Failed to update OXTRANSID', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function markOrderAsPaid(
        string $orderId,
        ?string $transactionId = null,
        ?DateTimeImmutable $paidAt = null
    ): bool {
        $timestamp = $paidAt ?? new DateTimeImmutable();

        try {
            // Build SQL based on whether we have transaction ID
            if ($transactionId !== null && $transactionId !== '') {
                $sql = "UPDATE oxorder
                        SET OXPAID = :paid,
                            OXTRANSSTATUS = 'OK',
                            OXTRANSID = CASE
                                WHEN OXTRANSID IS NULL OR OXTRANSID = '' THEN :transId
                                ELSE OXTRANSID
                            END
                        WHERE OXID = :orderId AND OXPAID = '0000-00-00 00:00:00'";

                $affected = $this->connection->executeStatement($sql, [
                    'paid' => $timestamp->format('Y-m-d H:i:s'),
                    'transId' => $transactionId,
                    'orderId' => $orderId,
                ]);
            } else {
                $sql = "UPDATE oxorder
                        SET OXPAID = :paid,
                            OXTRANSSTATUS = 'OK'
                        WHERE OXID = :orderId AND OXPAID = '0000-00-00 00:00:00'";

                $affected = $this->connection->executeStatement($sql, [
                    'paid' => $timestamp->format('Y-m-d H:i:s'),
                    'orderId' => $orderId,
                ]);
            }

            if ($affected > 0) {
                $this->logger->info('Order marked as paid', [
                    'order_id' => $orderId,
                    'transaction_id' => $transactionId,
                    'paid_at' => $timestamp->format('Y-m-d H:i:s'),
                ]);
            }

            return $affected > 0;
        } catch (\Exception $e) {
            $this->logger->error('Failed to mark order as paid', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
