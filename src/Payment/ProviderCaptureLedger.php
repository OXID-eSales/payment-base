<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Payment;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use InvalidArgumentException;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Contract\Transaction;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * One DB transaction per booking, the contract row locked (`FOR UPDATE`), the capture's transaction row written
 * before the contract's captured total moves: both or neither. A second report of the same capture id changes
 * nothing. The repositories must write on this ledger's connection (services.yaml wires all three to
 * doctrine.dbal.connection).
 *
 * @since 1.7.0
 */
final class ProviderCaptureLedger implements ProviderCaptureLedgerInterface
{
    private const CONTRACTS = 'oe_payments_contract';
    private const TRANSACTIONS = 'oe_payments_transaction';
    private const TYPE_CAPTURE = 'capture';
    private const STATUS_COMPLETED = 'completed';

    public function __construct(
        private readonly Connection $connection,
        private readonly ContractRepositoryInterface $contracts,
        private readonly TransactionRepositoryInterface $transactions,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function book(string $contractId, string $providerCaptureId, float $amount, string $currency, string $source): CaptureBooking
    {
        if ($providerCaptureId === '') {
            throw new InvalidArgumentException('A provider capture is booked by its capture id; none was given.');
        }
        if (!is_finite($amount) || $amount <= 0.0) {
            throw new InvalidArgumentException('A provider capture amount must be positive, got ' . $amount . '.');
        }

        $this->connection->beginTransaction();
        try {
            $booking = $this->bookLocked($contractId, $providerCaptureId, $amount, $currency, $source);
            $this->connection->commit();
        } catch (Throwable $failure) {
            $this->connection->rollBack();
            if (!$failure instanceof UniqueConstraintViolationException) {
                throw $failure;
            }
            $booking = CaptureBooking::duplicate((float) $this->contracts->findById($contractId)?->getCapturedAmount());
        }

        return $booking;
    }

    private function bookLocked(string $contractId, string $providerCaptureId, float $amount, string $currency, string $source): CaptureBooking
    {
        $shopId = $this->connection->fetchOne('SELECT OXSHOPID FROM ' . self::CONTRACTS . ' WHERE OXID = :id FOR UPDATE', ['id' => $contractId]);
        $contract = $shopId === false ? null : $this->contracts->findById($contractId);
        if ($contract === null) {
            throw new RuntimeException('Cannot book a provider capture: no contract ' . $contractId . '.');
        }
        if (strtoupper($contract->getCurrency()) !== strtoupper($currency)) {
            throw new InvalidArgumentException(sprintf('Capture %s is in %s, contract %s in %s.', $providerCaptureId, $currency, $contractId, $contract->getCurrency()));
        }
        if ($this->alreadyBooked($contractId, $providerCaptureId)) {
            return CaptureBooking::duplicate((float) $contract->getCapturedAmount());
        }

        $total = (float) $contract->getCapturedAmount() + $amount;
        $contract->setCapturedAmount($total);
        $contract->setCapturedAt(new DateTimeImmutable());
        $this->transactions->save($this->captureRow($contract, (int) $shopId, $providerCaptureId, $amount));
        $this->contracts->save($contract);
        $this->logger->info('[ProviderCaptureLedger] capture booked', [
            'contractId' => $contractId,
            'providerCaptureId' => $providerCaptureId,
            'amount' => $amount,
            'source' => $source,
        ]);

        return CaptureBooking::booked($total);
    }

    private function alreadyBooked(string $contractId, string $providerCaptureId): bool
    {
        return $this->connection->fetchOne(
            'SELECT 1 FROM ' . self::TRANSACTIONS . ' WHERE OXCONTRACTID = :contract AND OXTYPE = :type AND OXTRANSACTIONID = :capture',
            ['contract' => $contractId, 'type' => self::TYPE_CAPTURE, 'capture' => $providerCaptureId]
        ) !== false;
    }

    private function captureRow(PaymentContractInterface $contract, int $shopId, string $providerCaptureId, float $amount): Transaction
    {
        $row = new Transaction(
            'capture_' . bin2hex(random_bytes(16)),
            $shopId,
            (string) $contract->getOrderId(),
            (string) $contract->getId(),
            (string) $contract->getProvider(),
            self::TYPE_CAPTURE,
            self::STATUS_COMPLETED,
            $amount,
            $contract->getCurrency()
        );
        $row->setTransactionId($providerCaptureId);

        return $row;
    }
}
