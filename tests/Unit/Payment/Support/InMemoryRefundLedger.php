<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Payment\Support;

use OxidEsales\PaymentBase\Refund\ProviderRefundLedgerInterface;
use OxidEsales\PaymentBase\Refund\RefundBooking;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;

/**
 * The refund ledger on the in-memory contracts: once per provider refund id, onto the contract.
 */
final class InMemoryRefundLedger implements ProviderRefundLedgerInterface
{
    /** @var array<string, array{amount: float, source: string, succeeded: bool}> provider refund id => booking */
    public array $booked = [];

    public function __construct(private readonly ContractRepositoryInterface $contracts)
    {
    }

    public function book(string $contractId, string $providerRefundId, float $amount, string $currency, string $source, bool $succeeded): RefundBooking
    {
        $contract = $this->contracts->findById($contractId) ?? throw new \RuntimeException('no contract');
        if (isset($this->booked[$providerRefundId])) {
            return RefundBooking::duplicate((float) $contract->getRefundedAmount());
        }
        $this->booked[$providerRefundId] = ['amount' => $amount, 'source' => $source, 'succeeded' => $succeeded];
        $contract->addRefundedAmount($amount);
        $this->contracts->save($contract);

        return RefundBooking::booked((float) $contract->getRefundedAmount());
    }
}
