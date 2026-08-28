<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Service;

use InvalidArgumentException;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\NotFinishedOrderRepositoryInterface;
use OxidEsales\PaymentBase\Service\Result\NotFinishedOrderCleanupResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * @inheritDoc
 */
class NotFinishedOrderCleanupService implements NotFinishedOrderCleanupServiceInterface
{
    private const CANCELLATION_REASON = 'not_finished_cleanup';

    private const MINIMUM_PERIOD_DAYS = 1;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly NotFinishedOrderRepositoryInterface $orderRepository,
        private readonly ContractRepositoryInterface $contractRepository,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
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
        $vouchersReleased = 0;
        $failed = 0;

        foreach ($orderIds as $orderId) {
            try {
                // The order write is guarded on the status, so it is the
                // authority on whether this row was still ours to collect.
                // Everything else hangs off that answer, which keeps a
                // checkout finished mid-sweep from losing its vouchers.
                if (!$this->orderRepository->cancelOrder($orderId)) {
                    continue;
                }

                $ordersCancelled++;
                $vouchersReleased += $this->orderRepository->releaseVouchers($orderId);
                $contractsCancelled += $this->cancelLinkedContract($orderId) ? 1 : 0;
            } catch (Throwable $e) {
                // One locked or malformed row must not strand the rest of the
                // backlog: an aborting sweep never gets past its worst row.
                $failed++;
                $this->logger->error('Could not clean up the unfinished order', [
                    'orderId' => $orderId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return new NotFinishedOrderCleanupResult(
            scanned: count($orderIds),
            ordersCancelled: $ordersCancelled,
            contractsCancelled: $contractsCancelled,
            vouchersReleased: $vouchersReleased,
            failed: $failed,
            dryRun: false
        );
    }

    private function cancelLinkedContract(string $orderId): bool
    {
        $contract = $this->contractRepository->findByOrderId($orderId);

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
