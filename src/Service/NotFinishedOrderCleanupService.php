<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Service;

use InvalidArgumentException;
use OxidEsales\PaymentBase\Adapter\OrderRestockInterface;
use OxidEsales\PaymentBase\Checkout\Attempt\PaymentAttemptClosersInterface;
use OxidEsales\PaymentBase\Contract\AttemptClosure;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\LateMoney\ProviderPaymentGatewaysInterface;
use OxidEsales\PaymentBase\LateMoney\ProviderPaymentState;
use OxidEsales\PaymentBase\Logging\ThrowableSummaryInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\NotFinishedOrderRepositoryInterface;
use OxidEsales\PaymentBase\Service\Commit\ContractCommitServiceInterface;
use OxidEsales\PaymentBase\Service\Commit\PaymentConfirmation;
use OxidEsales\PaymentBase\Service\Result\NotFinishedOrderCleanupResult;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * @inheritDoc
 */
class NotFinishedOrderCleanupService implements NotFinishedOrderCleanupServiceInterface
{
    private const CANCELLATION_REASON = 'not_finished_cleanup';

    private const COMMIT_SOURCE = 'cleanup';

    private const MINIMUM_PERIOD_DAYS = 1;

    public function __construct(
        private readonly NotFinishedOrderRepositoryInterface $orderRepository,
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly OrderRestockInterface $restock,
        private readonly PaymentAttemptClosersInterface $attemptClosers,
        private readonly ProviderPaymentGatewaysInterface $gateways,
        private readonly ContractCommitServiceInterface $commits,
        private readonly ThrowableSummaryInterface $throwableSummary,
        private readonly LoggerInterface $logger
    ) {
    }

    public function cleanup(
        int $days,
        bool $dryRun = false,
        ?int $shopId = null,
        ?int $limit = null
    ): NotFinishedOrderCleanupResult {
        if ($days < self::MINIMUM_PERIOD_DAYS) {
            throw new InvalidArgumentException(
                'Cleanup period must be at least ' . self::MINIMUM_PERIOD_DAYS . ' day, got ' . $days . '.'
            );
        }

        $orderIds = $this->orderRepository->findStaleNotFinishedOrderIds($days, $shopId, $limit);

        if ($dryRun) {
            return new NotFinishedOrderCleanupResult(
                scanned: count($orderIds),
                ordersCancelled: 0,
                contractsCancelled: 0,
                contractsCommitted: 0,
                vouchersReleased: 0,
                failed: 0,
                dryRun: true
            );
        }

        return $this->cleanOrders($orderIds);
    }

    /**
     * @param array<int, string> $orderIds
     */
    private function cleanOrders(array $orderIds): NotFinishedOrderCleanupResult
    {
        $ordersCancelled = 0;
        $contractsCancelled = 0;
        $contractsCommitted = 0;
        $vouchersReleased = 0;
        $failed = 0;

        foreach ($orderIds as $orderId) {
            try {
                $contract = $this->contractRepository->findByOrderId($orderId);
                $open = $contract !== null && $this->isCancellable($contract);

                // The provider is asked before anything is cancelled here: a payment that was completed while the
                // customer's tab was closed is committed, not cancelled under a webhook that is on its way.
                if ($open && $this->commitIfPaidAtProvider($orderId, $contract)) {
                    $contractsCommitted++;
                    continue;
                }

                // The order write is guarded on the status, so it is the
                // authority on whether this row was still ours to collect.
                // Everything else hangs off that answer, which keeps a
                // checkout finished mid-sweep from losing its vouchers.
                if (!$this->orderRepository->cancelOrder($orderId)) {
                    continue;
                }

                $ordersCancelled++;
                $vouchersReleased += $this->orderRepository->releaseVouchers($orderId);
                // OPC-175 / S4 (G9): the early order took the stock.
                $this->restock->restock($orderId);
                $contractsCancelled += $this->cancelLinkedContract($orderId, $contract) ? 1 : 0;
            } catch (Throwable $e) {
                // One locked or malformed row must not strand the rest of the
                // backlog: an aborting sweep never gets past its worst row.
                // A provider that could not be asked lands here too: nothing
                // was cancelled on a guess, the next run asks again.
                $failed++;
                $this->logger->error('Could not clean up the unfinished order', [
                    'orderId' => $orderId,
                    'throwable' => $this->throwableSummary->fromThrowable($e),
                ]);
            }
        }

        return new NotFinishedOrderCleanupResult(
            scanned: count($orderIds),
            ordersCancelled: $ordersCancelled,
            contractsCancelled: $contractsCancelled,
            contractsCommitted: $contractsCommitted,
            vouchersReleased: $vouchersReleased,
            failed: $failed,
            dryRun: false
        );
    }

    /**
     * @return bool true when the provider said paid and the contract is committed now; false when it holds no
     *              money (its payment page is closed where the provider can) and the order may be cancelled
     *
     * @throws RuntimeException the provider said paid but the commit was refused: money without an order is for
     *                          the operator to see, so the order and the contract stay as they are
     */
    private function commitIfPaidAtProvider(string $orderId, PaymentContractInterface $contract): bool
    {
        if ($this->attemptClosers->close($contract) !== AttemptClosure::Paid) {
            return false;
        }

        $providerOrderId = (string) $contract->getProviderOrderId();
        $status = $this->gateways->forContract($contract)->statusOf($providerOrderId);
        if ($status->state === ProviderPaymentState::NoMoney) {
            throw new RuntimeException('The provider reports the attempt as paid but its payment holds no money.');
        }

        $outcome = $this->commits->commit(new PaymentConfirmation(
            contractId: (string) $contract->getId(),
            providerName: (string) $contract->getProvider(),
            authorizationId: $status->providerPaymentId,
            providerOrderId: $providerOrderId,
            amount: $status->amount,
            currency: $status->currency,
            requiresCapture: $status->state === ProviderPaymentState::Authorized,
            source: self::COMMIT_SOURCE,
        ));
        if (!$outcome->isSettled()) {
            throw new RuntimeException(sprintf(
                'The provider reports order %s as paid but its contract was not committed (%s).',
                $orderId,
                (string) ($outcome->reason ?? $outcome->outcome)
            ));
        }

        $this->logger->warning('Committed an unfinished order the provider reports as paid', [
            'orderId' => $orderId,
            'contractId' => $contract->getId(),
        ]);

        return true;
    }

    private function cancelLinkedContract(string $orderId, ?PaymentContractInterface $contract): bool
    {
        if ($contract === null) {
            return false;
        }

        if (!$this->isCancellable($contract)) {
            // Committed or already-terminal means money moved, or someone
            // else already recorded the ending. Either way the payment
            // history is not this collector's to rewrite.
            $this->logger->info('Left the settled contract of an unfinished order untouched', [
                'orderId' => $orderId,
                'contractId' => $contract->getId(),
                'state' => $contract->getStateValue(),
            ]);

            return false;
        }

        $contract->cancel(self::CANCELLATION_REASON);
        $this->contractRepository->save($contract);

        return true;
    }

    private function isCancellable(PaymentContractInterface $contract): bool
    {
        $state = $contract->getState();

        return !$state->isTerminal() && !$state->isCommitted();
    }
}
