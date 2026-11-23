<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Service;

use Throwable;
use RuntimeException;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use OxidEsales\PaymentBase\Logging\ThrowableSummaryInterface;
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
        private readonly LoggerInterface $logger,
        private readonly ThrowableSummaryInterface $throwableSummary
    ) {
    }

    public function updatePaidTimestamp(
        string $orderId,
        ?DateTimeImmutable $paidAt = null
    ): bool {
        $timestamp = $paidAt ?? new DateTimeImmutable();

        return $this->write(
            'OXPAID',
            "UPDATE oxorder SET OXPAID = :paid WHERE OXID = :orderId AND OXPAID = '0000-00-00 00:00:00'",
            ['paid' => $timestamp->format('Y-m-d H:i:s'), 'orderId' => $orderId]
        );
    }

    public function updateTransactionStatus(string $orderId, string $status): bool
    {
        return $this->write(
            'OXTRANSSTATUS',
            'UPDATE oxorder SET OXTRANSSTATUS = :status WHERE OXID = :orderId',
            ['status' => $status, 'orderId' => $orderId]
        );
    }

    public function updateTransactionId(string $orderId, string $transactionId): bool
    {
        return $this->write(
            'OXTRANSID',
            "UPDATE oxorder SET OXTRANSID = :transId WHERE OXID = :orderId AND (OXTRANSID IS NULL OR OXTRANSID = '')",
            ['transId' => $transactionId, 'orderId' => $orderId]
        );
    }

    public function markOrderAsPaid(
        string $orderId,
        ?string $transactionId = null,
        ?DateTimeImmutable $paidAt = null
    ): bool {
        $timestamp = $paidAt ?? new DateTimeImmutable();
        $columns = "OXPAID = :paid, OXTRANSSTATUS = 'OK'";
        $parameters = ['paid' => $timestamp->format('Y-m-d H:i:s'), 'orderId' => $orderId];
        if ($transactionId !== null && $transactionId !== '') {
            // An order that already names its transaction keeps it.
            $columns .= ", OXTRANSID = CASE WHEN OXTRANSID IS NULL OR OXTRANSID = '' THEN :transId ELSE OXTRANSID END";
            $parameters['transId'] = $transactionId;
        }

        return $this->write(
            'order paid mark',
            'UPDATE oxorder SET ' . $columns . " WHERE OXID = :orderId AND OXPAID = '0000-00-00 00:00:00'",
            $parameters
        );
    }

    /**
     * One guarded UPDATE of the order row. The answer says whether the row changed: `false` is the
     * repeated call (the value is set already), never a failure. A database error is logged and
     * leaves as an exception (sprint 147 AW): four callers ignored the `false` it used to become, and
     * the contract was committed while the order had no paid mark. The exception text is the summary
     * line: a database message carries the bound values (VR-18).
     *
     * @param array<string, string> $parameters
     *
     * @throws RuntimeException
     */
    private function write(string $what, string $sql, array $parameters): bool
    {
        try {
            $changed = $this->connection->executeStatement($sql, $parameters) > 0;
        } catch (Throwable $failure) {
            $this->logger->error('Failed to update ' . $what, [
                'order_id' => $parameters['orderId'],
                'throwable' => $this->throwableSummary->fromThrowable($failure),
            ]);

            throw new RuntimeException(
                'Failed to update ' . $what . ' of order ' . $parameters['orderId'] . ': '
                . $this->throwableSummary->toLine($failure),
                0,
                $failure
            );
        }
        if ($changed) {
            $this->logger->debug($what . ' updated', ['order_id' => $parameters['orderId']]);
        }

        return $changed;
    }
}
