<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Repository;

use Doctrine\DBAL\Connection;
use OxidEsales\PaymentBase\Contract\Transaction;
use RuntimeException;

/**
 * Doctrine DBAL implementation of TransactionRepositoryInterface
 *
 * Provider-agnostic repository for payment transaction persistence.
 */
class DoctrineTransactionRepository implements TransactionRepositoryInterface
{
    private const TABLE_NAME = 'oe_payments_transaction';

    public function __construct(
        private Connection $connection
    ) {
    }

    public function save(Transaction $transaction): void
    {
        $data = $this->prepareTransactionData($transaction);

        if ($this->exists($transaction->getId())) {
            $this->connection->update(self::TABLE_NAME, $data, ['OXID' => $transaction->getId()]);
            return;
        }

        $this->connection->insert(self::TABLE_NAME, $data);
    }

    public function findById(string $id): ?Transaction
    {
        $sql = 'SELECT * FROM ' . self::TABLE_NAME . ' WHERE OXID = :id';
        $data = $this->connection->fetchAssociative($sql, ['id' => $id]);

        if ($data === false) {
            return null;
        }

        return $this->hydrateTransaction($data);
    }

    /**
     * @return Transaction[]
     */
    public function findByOrderId(string $orderId): array
    {
        $sql = 'SELECT * FROM ' . self::TABLE_NAME . ' WHERE OXORDERID = :orderId ORDER BY OXCREATED ASC';
        $rows = $this->connection->fetchAllAssociative($sql, ['orderId' => $orderId]);

        return array_map(fn($row) => $this->hydrateTransaction($row), $rows);
    }

    /**
     * @return Transaction[]
     */
    public function findByContractId(string $contractId): array
    {
        $sql = 'SELECT * FROM ' . self::TABLE_NAME . ' WHERE OXCONTRACTID = :contractId ORDER BY OXCREATED ASC';
        $rows = $this->connection->fetchAllAssociative($sql, ['contractId' => $contractId]);

        return array_map(fn($row) => $this->hydrateTransaction($row), $rows);
    }

    public function findByProviderTransactionId(string $transactionId): ?Transaction
    {
        $sql = 'SELECT * FROM ' . self::TABLE_NAME . ' WHERE OXTRANSACTIONID = :transactionId';
        $data = $this->connection->fetchAssociative($sql, ['transactionId' => $transactionId]);

        if ($data === false) {
            return null;
        }

        return $this->hydrateTransaction($data);
    }

    /**
     * @return Transaction[]
     */
    public function findByTypeAndStatus(string $type, string $status): array
    {
        $sql = 'SELECT * FROM ' . self::TABLE_NAME . ' WHERE OXTYPE = :type AND OXSTATUS = :status ORDER BY OXCREATED DESC';
        $rows = $this->connection->fetchAllAssociative($sql, [
            'type' => $type,
            'status' => $status
        ]);

        return array_map(fn($row) => $this->hydrateTransaction($row), $rows);
    }

    /**
     * @return Transaction[]
     */
    public function findChildTransactions(string $parentTransactionId): array
    {
        $sql = 'SELECT * FROM ' . self::TABLE_NAME . ' WHERE OXPARENTTRANSACTIONID = :parentId ORDER BY OXCREATED ASC';
        $rows = $this->connection->fetchAllAssociative($sql, ['parentId' => $parentTransactionId]);

        return array_map(fn($row) => $this->hydrateTransaction($row), $rows);
    }

    public function exists(string $id): bool
    {
        $sql = 'SELECT COUNT(*) FROM ' . self::TABLE_NAME . ' WHERE OXID = :id';
        /** @phpstan-ignore-next-line */
        $count = (int) $this->connection->fetchOne($sql, ['id' => $id]);

        return $count > 0;
    }

    public function getTotalRefundedForContract(string $contractId): float
    {
        $sql = 'SELECT COALESCE(SUM(OXAMOUNT), 0) FROM ' . self::TABLE_NAME .
               ' WHERE OXCONTRACTID = :contractId AND OXTYPE = :type';
        $total = $this->connection->fetchOne($sql, [
            'contractId' => $contractId,
            'type' => 'refund'
        ]);

        /** @phpstan-ignore-next-line */
        return (float) $total;
    }

    public function logRefund(string $contractId, float $amount, string $refundId, string $reason): void
    {
        // Find the contract's order to get necessary details
        $sql = 'SELECT OXSHOPID, OXORDERID, OXPROVIDER, OXCURRENCY
                FROM ' . self::TABLE_NAME . '
                WHERE OXCONTRACTID = :contractId
                LIMIT 1';
        $data = $this->connection->fetchAssociative($sql, ['contractId' => $contractId]);

        if ($data === false) {
            throw new RuntimeException(
                sprintf('Cannot log refund: No transactions found for contract ID "%s"', $contractId)
            );
        }

        // Generate unique ID for refund transaction
        $refundTransactionId = 'refund_' . bin2hex(random_bytes(16));

        /** @phpstan-ignore-next-line */
        $transaction = new Transaction(
            $refundTransactionId,
            /** @phpstan-ignore-next-line */
            (int) $data['OXSHOPID'],
            /** @phpstan-ignore-next-line */
            (string) $data['OXORDERID'],
            $contractId,
            /** @phpstan-ignore-next-line */
            (string) $data['OXPROVIDER'],
            'refund',
            'completed',
            $amount,
            /** @phpstan-ignore-next-line */
            (string) $data['OXCURRENCY']
        );

        $transaction->setTransactionId($refundId);

        $this->save($transaction);
    }

    /**
     * @return array<string, mixed>
     */
    private function prepareTransactionData(Transaction $transaction): array
    {
        $transactionArray = $transaction->toArray();

        return [
            'OXID' => $transactionArray['id'],
            'OXSHOPID' => $transactionArray['shopId'],
            'OXORDERID' => $transactionArray['orderId'],
            'OXCONTRACTID' => $transactionArray['contractId'],
            'OXPROVIDER' => $transactionArray['provider'],
            'OXPROVIDERORDERID' => $transactionArray['providerOrderId'],
            'OXTRANSACTIONID' => $transactionArray['transactionId'],
            'OXTYPE' => $transactionArray['type'],
            'OXSTATUS' => $transactionArray['status'],
            'OXAMOUNT' => $transactionArray['amount'],
            'OXCURRENCY' => $transactionArray['currency'],
            'OXPAYMENTMETHODID' => $transactionArray['paymentMethodId'] ?? null,
            'OXPAYMENTMETHODTYPE' => $transactionArray['paymentMethodType'] ?? null,
            'OXPARENTTRANSACTIONID' => $transactionArray['parentTransactionId'] ?? null,
            'OXCREATED' => $transactionArray['createdAt'],
            'OXUPDATED' => $transactionArray['updatedAt'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hydrateTransaction(array $data): Transaction
    {
        /** @phpstan-ignore-next-line */
        return Transaction::fromArray([
            /** @phpstan-ignore-next-line */
            'id' => (string) $data['OXID'],
            /** @phpstan-ignore-next-line */
            'shopId' => (int) $data['OXSHOPID'],
            /** @phpstan-ignore-next-line */
            'orderId' => (string) $data['OXORDERID'],
            /** @phpstan-ignore-next-line */
            'contractId' => $data['OXCONTRACTID'] ? (string) $data['OXCONTRACTID'] : null,
            /** @phpstan-ignore-next-line */
            'provider' => (string) $data['OXPROVIDER'],
            /** @phpstan-ignore-next-line */
            'providerOrderId' => $data['OXPROVIDERORDERID'] ? (string) $data['OXPROVIDERORDERID'] : null,
            /** @phpstan-ignore-next-line */
            'transactionId' => $data['OXTRANSACTIONID'] ? (string) $data['OXTRANSACTIONID'] : null,
            /** @phpstan-ignore-next-line */
            'type' => (string) $data['OXTYPE'],
            /** @phpstan-ignore-next-line */
            'status' => (string) $data['OXSTATUS'],
            /** @phpstan-ignore-next-line */
            'amount' => (float) $data['OXAMOUNT'],
            /** @phpstan-ignore-next-line */
            'currency' => (string) $data['OXCURRENCY'],
            /** @phpstan-ignore-next-line */
            'paymentMethodId' => $data['OXPAYMENTMETHODID'] ? (string) $data['OXPAYMENTMETHODID'] : null,
            /** @phpstan-ignore-next-line */
            'paymentMethodType' => $data['OXPAYMENTMETHODTYPE'] ? (string) $data['OXPAYMENTMETHODTYPE'] : null,
            /** @phpstan-ignore-next-line */
            'parentTransactionId' => $data['OXPARENTTRANSACTIONID'] ? (string) $data['OXPARENTTRANSACTIONID'] : null,
            /** @phpstan-ignore-next-line */
            'createdAt' => (string) $data['OXCREATED'],
            /** @phpstan-ignore-next-line */
            'updatedAt' => (string) $data['OXUPDATED'],
        ]);
    }
}
