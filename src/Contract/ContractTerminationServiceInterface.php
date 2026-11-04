<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Contract;

/**
 * The provider-agnostic part of a provider's cancel, fail and expire notifications: guard the
 * state, end the contract, save it and let the linked order follow, so the admin order list and
 * the cleanup commands never see a cancelled contract with a still-open order.
 *
 * Auditing the transition stays with the provider: its transaction types differ.
 */
interface ContractTerminationServiceInterface
{
    public function cancel(PaymentContractInterface $contract, string $reason): TerminationOutcome;

    public function fail(PaymentContractInterface $contract, string $reason): TerminationOutcome;

    public function expire(PaymentContractInterface $contract): TerminationOutcome;
}
