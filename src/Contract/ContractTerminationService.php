<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Contract;

use OxidEsales\PaymentBase\Adapter\ContractLinkedOrderUpdaterInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;

final class ContractTerminationService implements ContractTerminationServiceInterface
{
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ContractLinkedOrderUpdaterInterface $orderUpdater,
    ) {
    }

    public function cancel(PaymentContractInterface $contract, string $reason): TerminationOutcome
    {
        if ($contract->getState()->isTerminal()) {
            return TerminationOutcome::AlreadyTerminal;
        }

        $contract->cancel($reason);
        $this->contractRepository->save($contract);
        $this->mirrorCancellation($contract);

        return TerminationOutcome::Terminated;
    }

    public function fail(PaymentContractInterface $contract, string $reason): TerminationOutcome
    {
        if ($contract->getState()->isTerminal()) {
            return TerminationOutcome::AlreadyTerminal;
        }

        $contract->fail($reason);
        $this->contractRepository->save($contract);
        $orderId = $contract->getOrderId();
        if ($orderId !== null && $orderId !== '') {
            $this->orderUpdater->markFailed($orderId, $reason);
        }

        return TerminationOutcome::Terminated;
    }

    public function expire(PaymentContractInterface $contract): TerminationOutcome
    {
        if ($contract->getState()->isTerminal()) {
            return TerminationOutcome::AlreadyTerminal;
        }
        // `committed` is not terminal, but the state machine refuses expire() there: acting would throw
        // out of the provider's webhook and the provider would retry for ever.
        if ($contract->getState()->isCommitted()) {
            return TerminationOutcome::Committed;
        }

        $contract->expire();
        $this->contractRepository->save($contract);
        // As for cancel: without the mirror the order stays NOT_FINISHED and, as every contract-keyed
        // cleanup skips terminal states, expiring the contract would make the order unreachable.
        $this->mirrorCancellation($contract);

        return TerminationOutcome::Terminated;
    }

    private function mirrorCancellation(PaymentContractInterface $contract): void
    {
        $orderId = $contract->getOrderId();
        if ($orderId === null || $orderId === '') {
            return;
        }

        $this->orderUpdater->markCancelled($orderId);
    }
}
