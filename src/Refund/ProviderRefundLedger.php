<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Refund;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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
 * One DB transaction per booking: the contract row is locked (`FOR UPDATE`), so
 * two reports of the same refund - the admin action and the provider's webhook,
 * arriving together - are serialised; the second finds the first one's
 * transaction row and changes nothing. The refund row is written before the
 * contract's refunded total moves, both or neither. UNQ_CONTRACT_TYPE_TXN on
 * the transaction table backs this up.
 *
 * The two repositories must write on this ledger's connection (services.yaml wires
 * all three to doctrine.dbal.connection): on another connection their update would
 * wait for the lock this transaction holds.
 */
class ProviderRefundLedger implements ProviderRefundLedgerInterface
{
    private const CONTRACTS = 'oe_payments_contract';
    private const TRANSACTIONS = 'oe_payments_transaction';
    private const TYPE_REFUND = 'refund';
    private const STATUS_COMPLETED = 'completed';

    public function __construct(
        private readonly Connection $connection,
        private readonly ContractRepositoryInterface $contracts,
        private readonly TransactionRepositoryInterface $transactions,
        private readonly RefundSettledPolicyInterface $settledPolicy,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function book(
        string $contractId,
        string $providerRefundId,
        float $amount,
        string $currency,
        string $source,
        bool $succeeded
    ): RefundBooking {
        if ($providerRefundId === '') {
            throw new InvalidArgumentException('A provider refund is booked by its refund id; none was given.');
        }
        if ($amount <= 0.0) {
            throw new InvalidArgumentException('A provider refund amount must be positive, got ' . $amount . '.');
        }

        $this->connection->beginTransaction();
        try {
            $booking = $this->bookLocked($contractId, $providerRefundId, $amount, $currency, $source);
            $this->connection->commit();
        } catch (Throwable $e) {
            $this->connection->rollBack();
            if (!$e instanceof UniqueConstraintViolationException) {
                throw $e;
            }
            $booking = RefundBooking::duplicate($this->refundedTotalOf($contractId));
        }

        $this->settle($contractId, $succeeded);

        return $booking;
    }

    /**
     * After the commit, not inside it: the stock write goes through the shop's own database layer and could not be
     * rolled back with the booking. Asked again on a duplicate on purpose: a refund booked as pending and reported
     * succeeded later settles then, and a settlement that failed after its booking is retried by the provider's
     * redelivery. The policy restocks once.
     */
    private function settle(string $contractId, bool $succeeded): void
    {
        $contract = $this->contracts->findById($contractId);
        if ($contract === null) {
            throw new RuntimeException('Contract ' . $contractId . ' vanished after its refund was booked.');
        }

        $this->settledPolicy->settle($contract, $succeeded);
    }

    private function bookLocked(
        string $contractId,
        string $providerRefundId,
        float $amount,
        string $currency,
        string $source
    ): RefundBooking {
        $shopId = $this->connection->fetchOne(
            'SELECT OXSHOPID FROM ' . self::CONTRACTS . ' WHERE OXID = :id FOR UPDATE',
            ['id' => $contractId]
        );
        $contract = $shopId === false ? null : $this->contracts->findById($contractId);
        if ($contract === null) {
            throw new RuntimeException('Cannot book a provider refund: no contract ' . $contractId . '.');
        }
        if (strtoupper($contract->getCurrency()) !== strtoupper($currency)) {
            throw new InvalidArgumentException(sprintf(
                'Refund %s is in %s, contract %s in %s.',
                $providerRefundId,
                $currency,
                $contractId,
                $contract->getCurrency()
            ));
        }

        if ($this->alreadyBooked($contractId, $providerRefundId)) {
            $this->logger->info('[ProviderRefundLedger] refund already booked', [
                'contractId' => $contractId,
                'providerRefundId' => $providerRefundId,
                'source' => $source,
            ]);

            return RefundBooking::duplicate((float) $contract->getRefundedAmount());
        }

        $this->transactions->save($this->refundRow($contract, (int) $shopId, $providerRefundId, $amount));
        $contract->addRefundedAmount($amount);
        $this->contracts->save($contract);

        $this->logger->info('[ProviderRefundLedger] refund booked', [
            'contractId' => $contractId,
            'providerRefundId' => $providerRefundId,
            'amount' => $amount,
            'source' => $source,
        ]);

        return RefundBooking::booked((float) $contract->getRefundedAmount());
    }

    private function alreadyBooked(string $contractId, string $providerRefundId): bool
    {
        return $this->connection->fetchOne(
            'SELECT 1 FROM ' . self::TRANSACTIONS . '
             WHERE OXCONTRACTID = :contract AND OXTYPE = :type AND OXTRANSACTIONID = :refund',
            ['contract' => $contractId, 'type' => self::TYPE_REFUND, 'refund' => $providerRefundId]
        ) !== false;
    }

    private function refundRow(
        PaymentContractInterface $contract,
        int $shopId,
        string $providerRefundId,
        float $amount
    ): Transaction {
        $row = new Transaction(
            'refund_' . bin2hex(random_bytes(16)),
            $shopId,
            (string) $contract->getOrderId(),
            (string) $contract->getId(),
            (string) $contract->getProvider(),
            self::TYPE_REFUND,
            self::STATUS_COMPLETED,
            $amount,
            $contract->getCurrency()
        );
        $row->setTransactionId($providerRefundId);

        return $row;
    }

    private function refundedTotalOf(string $contractId): float
    {
        return (float) $this->contracts->findById($contractId)?->getRefundedAmount();
    }
}
