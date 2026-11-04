<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Reconciliation;

use Doctrine\DBAL\Connection;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractFulfillmentServiceInterface;
use OxidEsales\PaymentBase\Service\OrderPaymentStateServiceInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * OXPAID is the payment date for accounting exports and dunning, so it only ever comes from the
 * provider: when the provider confirms the payment but gives no time, the order is left for manual
 * review instead of being stamped with the run time of a catch-up job.
 */
final class OxpaidReconciliationService implements OxpaidReconciliationServiceInterface
{
    /**
     * @param iterable<ProviderPaymentStatusInterface> $statusPorts
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ContractFulfillmentServiceInterface $contractFulfillmentService,
        private readonly OrderPaymentStateServiceInterface $orderPaymentStateService,
        private readonly iterable $statusPorts,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function providers(): array
    {
        $providers = [];
        foreach ($this->statusPorts as $port) {
            $providers[] = $port->provider();
        }

        return $providers;
    }

    public function findUnpaidOrders(string $provider, int $maxAgeDays): array
    {
        $sql = "SELECT o.OXID, o.OXORDERNR, o.OXORDERDATE, c.OXPROVIDERORDERID
                FROM oxorder o
                INNER JOIN oe_payments_contract c ON c.OXORDERID = o.OXID
                WHERE o.OXPAID = '0000-00-00 00:00:00'
                  AND c.OXPROVIDER = ?
                  AND o.OXORDERDATE >= DATE_SUB(NOW(), INTERVAL ? DAY)
                ORDER BY o.OXORDERDATE DESC";

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, [$provider, $maxAgeDays]);

        return $rows;
    }

    public function reconcileOrder(string $orderId): ReconciliationResult
    {
        $contract = $this->contractRepository->findByOrderId($orderId);
        if ($contract === null) {
            $this->audit($orderId, '', 'ERROR', 'NO_CONTRACT', 'No contract found');

            return new ReconciliationResult(
                $orderId,
                '',
                false,
                ReconciliationResult::ACTION_NO_CONTRACT,
                'No contract found for order: ' . $orderId
            );
        }

        $providerOrderId = (string) $contract->getProviderOrderId();
        if ($providerOrderId === '') {
            $this->audit($orderId, '', 'ERROR', 'NO_CONTRACT', 'Contract has no provider order id');

            return new ReconciliationResult(
                $orderId,
                '',
                false,
                ReconciliationResult::ACTION_NO_CONTRACT,
                'Contract of order ' . $orderId . ' is not linked to a provider payment'
            );
        }

        try {
            return $this->heal($orderId, $providerOrderId, $contract);
        } catch (Throwable $e) {
            $this->audit($orderId, $providerOrderId, 'ERROR', 'CONTRACT_UNCHANGED', $e->getMessage());

            return new ReconciliationResult(
                $orderId,
                $providerOrderId,
                false,
                ReconciliationResult::ACTION_ERROR,
                $e->getMessage()
            );
        }
    }

    public function reconcileAll(string $provider, int $maxAgeDays, bool $dryRun): array
    {
        $results = [];
        foreach ($this->findUnpaidOrders($provider, $maxAgeDays) as $order) {
            $orderId = is_string($order['OXID'] ?? null) ? $order['OXID'] : '';
            if ($dryRun) {
                $results[] = $this->dryRunResult($orderId, $order);
                continue;
            }
            $results[] = $this->reconcileOrder($orderId);
        }

        return $results;
    }

    /** @param array<string, mixed> $order */
    private function dryRunResult(string $orderId, array $order): ReconciliationResult
    {
        $providerOrderId = is_string($order['OXPROVIDERORDERID'] ?? null) ? $order['OXPROVIDERORDERID'] : '';
        $orderNumber = is_scalar($order['OXORDERNR'] ?? null) ? (string) $order['OXORDERNR'] : '';
        $orderDate = is_scalar($order['OXORDERDATE'] ?? null) ? (string) $order['OXORDERDATE'] : '';

        // A dry run performs no work, so it must not count as a success.
        return new ReconciliationResult(
            $orderId,
            $providerOrderId,
            false,
            ReconciliationResult::ACTION_DRY_RUN,
            'Would check order #' . $orderNumber . ' from ' . $orderDate
        );
    }

    private function heal(
        string $orderId,
        string $providerOrderId,
        PaymentContractInterface $contract
    ): ReconciliationResult {
        $status = $this->portFor((string) $contract->getProvider())->statusOf($providerOrderId);
        if (!$status->paid) {
            return $this->skipped($orderId, $providerOrderId, 'Payment not paid. Status: ' . $status->status);
        }
        if ($status->paidAt === null) {
            $this->audit($orderId, $providerOrderId, 'SKIPPED', 'CONTRACT_UNCHANGED', 'Provider reported no payment timestamp');

            return $this->skipped(
                $orderId,
                $providerOrderId,
                'Payment is paid but the provider reported no payment timestamp; needs manual review'
            );
        }
        if (!$this->orderPaymentStateService->updatePaidTimestamp($orderId, $status->paidAt)) {
            return $this->skipped($orderId, $providerOrderId, 'Nothing to reconcile (already consistent)');
        }

        $contractUpdated = $this->contractFulfillmentService->fulfill($contract);
        $this->audit($orderId, $providerOrderId, 'SUCCESS', $contractUpdated ? 'CONTRACT_FULFILLED' : 'CONTRACT_UNCHANGED');

        return new ReconciliationResult(
            $orderId,
            $providerOrderId,
            true,
            ReconciliationResult::ACTION_UPDATED,
            'OXPAID updated from the provider payment status',
            $contractUpdated
        );
    }

    private function skipped(string $orderId, string $providerOrderId, string $reason): ReconciliationResult
    {
        return new ReconciliationResult(
            $orderId,
            $providerOrderId,
            false,
            ReconciliationResult::ACTION_SKIPPED,
            $reason
        );
    }

    private function portFor(string $provider): ProviderPaymentStatusInterface
    {
        foreach ($this->statusPorts as $port) {
            if ($port->provider() === $provider) {
                return $port;
            }
        }

        throw new RuntimeException('No payment status port registered for provider "' . $provider . '"');
    }

    /**
     * Three contract states, not two: "no contract" must never be reported for a contract that
     * exists but was left unchanged.
     */
    private function audit(
        string $orderId,
        string $providerOrderId,
        string $outcome,
        string $contractFlag,
        ?string $error = null
    ): void {
        $message = sprintf(
            'OXPAID reconciliation %s: Order=%s ProviderOrder=%s %s%s',
            $outcome,
            $orderId,
            $providerOrderId,
            $contractFlag,
            $error !== null ? ' Error: ' . $error : ''
        );

        if ($outcome === 'ERROR') {
            $this->logger->error($message);

            return;
        }
        $this->logger->info($message);
    }
}
