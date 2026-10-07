<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Service\Commit;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\Event\Payment\PaymentAuthorizedEvent;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\StaleContractException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The one way to turn "paid" into COMMITTED outside the return leg.
 *
 * Decides, in order: unknown contract → refused; committed / fulfilled →
 * already processed; terminal (cancelled, expired, failed) → refused and
 * logged as an error, because the PSP holds money nothing will ship for;
 * amount or currency off the snapshot → refused; otherwise
 * PaymentAuthorizedEvent goes through the shared chain
 * (PaymentAuthorizedEventHandler → ContractReadyToCommitEvent →
 * ContractCommitmentHandler), exactly as CheckoutReturnResponder raises it.
 * A stale save (the other leg got there first) is reloaded once: committed
 * is the wanted outcome, still open gets the chain once more, twice stale is
 * left to the other leg.
 *
 * @since 3.0.0
 */
class ContractCommitService implements ContractCommitServiceInterface
{
    private const AMOUNT_TOLERANCE = 0.005;

    public function __construct(
        private readonly ContractRepositoryInterface $contracts,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function commit(PaymentConfirmation $confirmation): CommitOutcome
    {
        $contract = $this->contracts->findById($confirmation->contractId);
        if ($contract === null) {
            $this->logger->warning('[ContractCommitService] no contract for a payment confirmation', [
                'contractId' => $confirmation->contractId,
                'provider' => $confirmation->providerName,
                'source' => $confirmation->source,
            ]);

            return CommitOutcome::refused('contract_not_found');
        }

        $refusal = $this->refusal($contract, $confirmation);
        if ($refusal !== null) {
            return $refusal;
        }

        try {
            return $this->runChain($contract, $confirmation);
        } catch (StaleContractException $e) {
            return $this->settleOnFreshCopy($confirmation, $e->getMessage());
        }
    }

    private function refusal(PaymentContractInterface $contract, PaymentConfirmation $confirmation): ?CommitOutcome
    {
        $state = $contract->getState();
        if ($state->isCommitted() || $state->isFulfilled()) {
            return CommitOutcome::alreadyProcessed($contract->getOrderId());
        }

        if ($state->isTerminal()) {
            $this->logger->error('[ContractCommitService] provider confirms payment for a contract that is already closed', [
                'contractId' => $contract->getId(),
                'state' => $contract->getStateValue(),
                'provider' => $confirmation->providerName,
                'authorizationId' => $confirmation->authorizationId,
                'amount' => $confirmation->amount,
                'currency' => $confirmation->currency,
            ]);

            return CommitOutcome::refused('contract_' . $contract->getStateValue());
        }

        if (!$this->amountMatches($contract, $confirmation)) {
            $this->logger->error('[ContractCommitService] confirmed amount does not match the contract', [
                'contractId' => $contract->getId(),
                'contractAmount' => $contract->getAmount(),
                'contractCurrency' => $contract->getCurrency(),
                'confirmedAmount' => $confirmation->amount,
                'confirmedCurrency' => $confirmation->currency,
                'provider' => $confirmation->providerName,
            ]);

            return CommitOutcome::refused('amount_mismatch');
        }

        return null;
    }

    private function amountMatches(PaymentContractInterface $contract, PaymentConfirmation $confirmation): bool
    {
        return abs($contract->getAmount() - $confirmation->amount) <= self::AMOUNT_TOLERANCE
            && strtoupper($contract->getCurrency()) === strtoupper($confirmation->currency);
    }

    /**
     * @throws StaleContractException
     */
    private function runChain(PaymentContractInterface $contract, PaymentConfirmation $confirmation): CommitOutcome
    {
        $context = $this->buildContext($contract, $confirmation);

        $this->dispatcher->dispatch(new PaymentAuthorizedEvent(
            $context,
            $confirmation->authorizationId,
            $confirmation->providerOrderId,
            $confirmation->amount,
            strtoupper($confirmation->currency),
        ));

        if (!$contract->getState()->isCommitted() && !$contract->getState()->isFulfilled()) {
            $this->logger->info('[ContractCommitService] chain ran, contract still open', [
                'contractId' => $contract->getId(),
                'state' => $contract->getStateValue(),
            ]);

            return CommitOutcome::pending();
        }

        $orderId = $context->get('orderId');

        return CommitOutcome::committed(is_string($orderId) && $orderId !== '' ? $orderId : $contract->getOrderId());
    }

    private function settleOnFreshCopy(PaymentConfirmation $confirmation, string $cause): CommitOutcome
    {
        $fresh = $this->contracts->findById($confirmation->contractId);
        if ($fresh === null) {
            return CommitOutcome::refused('contract_not_found');
        }

        if ($fresh->getState()->isCommitted() || $fresh->getState()->isFulfilled()) {
            $this->logger->info('[ContractCommitService] yielded to the other leg', [
                'contractId' => $fresh->getId(),
                'cause' => $cause,
            ]);

            return CommitOutcome::alreadyProcessed($fresh->getOrderId());
        }

        try {
            return $this->runChain($fresh, $confirmation);
        } catch (StaleContractException $again) {
            $this->logger->warning('[ContractCommitService] contract changed twice; leaving it to the other leg', [
                'contractId' => $fresh->getId(),
                'error' => $again->getMessage(),
            ]);

            return CommitOutcome::refused('stale_contract');
        }
    }

    private function buildContext(PaymentContractInterface $contract, PaymentConfirmation $confirmation): EventContext
    {
        $context = new EventContext(array_merge(
            [
                'providerName' => $confirmation->providerName,
                'contract_id' => $contract->getId(),
                'contractId' => $contract->getId(),
                'requiresCapture' => $confirmation->requiresCapture,
                'commitSource' => $confirmation->source,
            ],
            $confirmation->extraContext,
        ));
        $context->setContract($contract);

        return $context;
    }
}
