<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Refund;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Contract\Transaction;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * The total booked so far is the sum of the contract's chargeback rows (reversals are negative), read under the
 * contract's row lock like ProviderRefundLedger does: two deliveries of one webhook are serialised, the second one
 * finds the total already booked. Same wiring requirement: all three collaborators on one connection.
 */
class ProviderChargebackLedger implements ProviderChargebackLedgerInterface
{
    private const CONTRACTS = 'oe_payments_contract';
    private const TRANSACTIONS = 'oe_payments_transaction';
    private const TYPE_CHARGEBACK = 'chargeback';
    private const STATUS_COMPLETED = 'completed';
    private const REVERSAL_SUFFIX = ':reversal';

    /** OXAMOUNT has two decimals; a difference that rounds to zero there would book an empty row at every replay. */
    private const AMOUNT_SCALE = 2;

    public function __construct(
        private readonly Connection $connection,
        private readonly ContractRepositoryInterface $contracts,
        private readonly TransactionRepositoryInterface $transactions,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function book(
        string $contractId,
        ?string $providerChargebackId,
        float $cumulativeAmount,
        string $currency,
        string $source
    ): ChargebackBooking {
        if ($cumulativeAmount < 0.0) {
            throw new InvalidArgumentException('A chargeback total cannot be negative, got ' . $cumulativeAmount . '.');
        }

        $this->connection->beginTransaction();
        try {
            $booking = $this->bookLocked($contractId, $providerChargebackId, $cumulativeAmount, $currency, $source);
            $this->connection->commit();

            return $booking;
        } catch (Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    private function bookLocked(
        string $contractId,
        ?string $providerChargebackId,
        float $cumulativeAmount,
        string $currency,
        string $source
    ): ChargebackBooking {
        $shopId = $this->connection->fetchOne(
            'SELECT OXSHOPID FROM ' . self::CONTRACTS . ' WHERE OXID = :id FOR UPDATE',
            ['id' => $contractId]
        );
        $contract = $shopId === false ? null : $this->contracts->findById($contractId);
        if ($contract === null) {
            throw new RuntimeException('Cannot book a provider chargeback: no contract ' . $contractId . '.');
        }
        if (strtoupper($contract->getCurrency()) !== strtoupper($currency)) {
            throw new InvalidArgumentException(sprintf(
                'Chargeback total is in %s, contract %s in %s.',
                $currency,
                $contractId,
                $contract->getCurrency()
            ));
        }

        $bookedTotal = $this->bookedTotal($contractId);
        $delta = round($cumulativeAmount - $bookedTotal, self::AMOUNT_SCALE);
        if ($delta === 0.0) {
            return new ChargebackBooking(false, 0.0, $bookedTotal);
        }

        $this->transactions->save($this->auditRow($contract, (int) $shopId, $providerChargebackId, $delta));
        $this->logger->info('[ProviderChargebackLedger] chargeback change booked', [
            'contractId' => $contractId,
            'providerChargebackId' => $providerChargebackId,
            'delta' => $delta,
            'total' => $cumulativeAmount,
            'source' => $source,
        ]);

        return new ChargebackBooking(true, $delta, $cumulativeAmount);
    }

    private function bookedTotal(string $contractId): float
    {
        return (float) $this->connection->fetchOne(
            'SELECT COALESCE(SUM(OXAMOUNT), 0) FROM ' . self::TRANSACTIONS . '
             WHERE OXCONTRACTID = :contract AND OXTYPE = :type',
            ['contract' => $contractId, 'type' => self::TYPE_CHARGEBACK]
        );
    }

    private function auditRow(
        PaymentContractInterface $contract,
        int $shopId,
        ?string $providerChargebackId,
        float $delta
    ): Transaction {
        $row = new Transaction(
            'chargeback_' . bin2hex(random_bytes(16)),
            $shopId,
            (string) $contract->getOrderId(),
            (string) $contract->getId(),
            (string) $contract->getProvider(),
            self::TYPE_CHARGEBACK,
            self::STATUS_COMPLETED,
            $delta,
            $contract->getCurrency()
        );
        $row->setTransactionId($this->rowId($providerChargebackId, $delta));

        return $row;
    }

    private function rowId(?string $providerChargebackId, float $delta): ?string
    {
        if ($providerChargebackId === null || $providerChargebackId === '') {
            return null;
        }

        return $delta < 0.0 ? $providerChargebackId . self::REVERSAL_SUFFIX : $providerChargebackId;
    }
}
