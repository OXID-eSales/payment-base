<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Payment\Support;

use OxidEsales\PaymentBase\Payment\CaptureBooking;
use OxidEsales\PaymentBase\Payment\ProviderCaptureLedgerInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;

/**
 * The capture ledger on the in-memory contracts: once per provider capture id, onto the contract.
 */
final class InMemoryCaptureLedger implements ProviderCaptureLedgerInterface
{
    /** @var array<string, float> provider capture id => amount */
    public array $booked = [];

    public function __construct(private readonly ContractRepositoryInterface $contracts)
    {
    }

    public function book(string $contractId, string $providerCaptureId, float $amount, string $currency, string $source): CaptureBooking
    {
        $contract = $this->contracts->findById($contractId) ?? throw new \RuntimeException('no contract');
        if (isset($this->booked[$providerCaptureId])) {
            return CaptureBooking::duplicate((float) $contract->getCapturedAmount());
        }
        $this->booked[$providerCaptureId] = $amount;
        $contract->setCapturedAmount((float) $contract->getCapturedAmount() + $amount);
        $this->contracts->save($contract);

        return CaptureBooking::booked((float) $contract->getCapturedAmount());
    }
}
