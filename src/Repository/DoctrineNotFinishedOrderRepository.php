<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use OxidEsales\PaymentBase\Logging\ThrowableSummaryInterface;
use RuntimeException;

/**
 * Doctrine implementation of {@see NotFinishedOrderRepositoryInterface}.
 *
 * Plain SQL rather than the OXID order model on purpose: the collector runs
 * over a whole backlog from the CLI, where loading and saving a full model per
 * row would buy nothing and cost a great deal.
 */
class DoctrineNotFinishedOrderRepository implements NotFinishedOrderRepositoryInterface
{
    private const TABLE_ORDERS = 'oxorder';

    private const TABLE_VOUCHERS = 'oxvouchers';

    private const STATUS_NOT_FINISHED = 'NOT_FINISHED';

    private const STATUS_CANCELLED = 'CANCELLED';

    /**
     * VR-18: the mail store is injected, no longer defaulted to a Doctrine one built here. VR-25: a failure's
     * message may carry statement parameters, so the refusals name the throwable by its summary line.
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly ContractOrderMailStoreInterface $deferredMails,
        private readonly ThrowableSummaryInterface $throwableSummary
    ) {
    }

    /**
     * @inheritDoc
     */
    public function findStaleNotFinishedOrderIds(int $days, ?int $shopId = null, ?int $limit = null): array
    {
        $sql = 'SELECT OXID FROM ' . self::TABLE_ORDERS . '
                WHERE OXTRANSSTATUS = :status
                  AND OXSTORNO = 0
                  AND OXORDERDATE < DATE_SUB(NOW(), INTERVAL :days DAY)';

        $parameters = ['status' => self::STATUS_NOT_FINISHED, 'days' => $days];

        if ($shopId !== null) {
            $sql .= ' AND OXSHOPID = :shopId';
            $parameters['shopId'] = $shopId;
        }

        $sql .= ' ORDER BY OXORDERDATE ASC';

        if ($limit !== null) {
            // MySQL will not take a bound parameter in LIMIT under real
            // prepared statements, so the value is cast to int and inlined.
            $sql .= ' LIMIT ' . max(1, $limit);
        }

        try {
            /** @var array<int, string> $ids */
            $ids = $this->connection->fetchFirstColumn($sql, $parameters);

            return $ids;
        } catch (Exception $e) {
            // An empty array here is indistinguishable from "nothing to clean",
            // which would let a broken query masquerade as a healthy shop for
            // as long as the cron keeps running.
            throw new RuntimeException('Failed to query unfinished orders: ' . $this->throwableSummary->toLine($e), 0, $e);
        }
    }

    public function cancelOrder(string $orderId): bool
    {
        $sql = 'UPDATE ' . self::TABLE_ORDERS . '
                SET OXSTORNO = 1, OXTRANSSTATUS = :cancelled
                WHERE OXID = :id AND OXTRANSSTATUS = :notFinished';

        try {
            $affected = (int) $this->connection->executeStatement($sql, [
                'cancelled' => self::STATUS_CANCELLED,
                'id' => $orderId,
                'notFinished' => self::STATUS_NOT_FINISHED,
            ]);
        } catch (Exception $e) {
            throw new RuntimeException(
                'Failed to cancel order ' . $orderId . ': ' . $this->throwableSummary->toLine($e),
                0,
                $e
            );
        }

        if ($affected === 0) {
            return false;
        }

        $this->dropDeferredOrderMails($orderId);

        return true;
    }

    /**
     * STRP-176 — an abandoned checkout is never paid, so the order mails
     * waiting on its contract must never go out, and hold the customer's data
     * for nothing. Best-effort: the storno took and must be reported as such;
     * the sender skips stornoed orders, so leftover mails cost storage, not a
     * mail.
     */
    private function dropDeferredOrderMails(string $orderId): void
    {
        try {
            $this->deferredMails->dropByOrderId($orderId);
        } catch (RuntimeException) {
            // The store's message carries the SQL error; the storno stands.
        }
    }

    public function isNotFinished(string $orderId): bool
    {
        $sql = 'SELECT OXTRANSSTATUS FROM ' . self::TABLE_ORDERS . ' WHERE OXID = :id';

        try {
            $status = $this->connection->fetchOne($sql, ['id' => $orderId]);
        } catch (Exception $e) {
            throw new RuntimeException(
                'Failed to read the state of order ' . $orderId . ': ' . $this->throwableSummary->toLine($e),
                0,
                $e
            );
        }

        return $status === self::STATUS_NOT_FINISHED;
    }

    public function releaseVouchers(string $orderId): int
    {
        // Mirrors what early order creation did via Order::finalizeOrder() ->
        // markVouchers(): OXORDERID, OXUSERID, OXDISCOUNT and OXDATEUSED are
        // stamped there and have to be cleared for the voucher to be reusable.
        $sql = 'UPDATE ' . self::TABLE_VOUCHERS . '
                SET OXORDERID = \'\', OXUSERID = \'\', OXDISCOUNT = 0, OXDATEUSED = NULL, OXRESERVED = 0
                WHERE OXORDERID = :orderId';

        try {
            return (int) $this->connection->executeStatement($sql, ['orderId' => $orderId]);
        } catch (Exception $e) {
            throw new RuntimeException(
                'Failed to release vouchers of order ' . $orderId . ': ' . $this->throwableSummary->toLine($e),
                0,
                $e
            );
        }
    }
}
