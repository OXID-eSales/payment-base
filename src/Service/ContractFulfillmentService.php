<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Service;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractFulfilledEvent;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for contract fulfillment operations.
 *
 * SOLID Principles:
 * - SRP: Only handles contract fulfillment
 * - OCP: Open for extension via interface
 * - DIP: Depends on repository and dispatcher abstractions
 *
 * DRY: Single location for all fulfillment logic that was previously
 * duplicated across 8+ locations in the codebase.
 *
 * @since 1.0.0
 */
class ContractFulfillmentService implements ContractFulfillmentServiceInterface
{
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger
    ) {
    }

    public function fulfill(PaymentContractInterface $contract): bool
    {
        if (!$this->canFulfill($contract)) {
            $this->logger->debug('Cannot fulfill contract', [
                'contract_id' => $contract->getId(),
                'state' => $contract->getStateValue(),
                'reason' => $this->getCannotFulfillReason($contract),
            ]);
            return false;
        }

        $contract->fulfill();
        $this->contractRepository->save($contract);
        $this->dispatchFulfilledEvent($contract);

        $this->logger->info('Contract fulfilled', [
            'contract_id' => $contract->getId(),
            'order_id' => $contract->getOrderId(),
            'provider_order_id' => $contract->getProviderOrderId(),
        ]);

        return true;
    }

    public function fulfillByProviderOrderId(string $providerOrderId): ?bool
    {
        $contract = $this->contractRepository->findByProviderOrderId($providerOrderId);

        if ($contract === null) {
            $this->logger->debug('Contract not found by provider order ID', [
                'provider_order_id' => $providerOrderId,
            ]);
            return null;
        }

        return $this->fulfill($contract);
    }

    public function fulfillByContractId(string $contractId): ?bool
    {
        $contract = $this->contractRepository->findById($contractId);

        if ($contract === null) {
            $this->logger->debug('Contract not found by ID', [
                'contract_id' => $contractId,
            ]);
            return null;
        }

        return $this->fulfill($contract);
    }

    /**
     * Check if contract can be fulfilled.
     *
     * Guards:
     * - Contract must be in COMMITTED state
     * - Contract must not already be fulfilled
     */
    private function canFulfill(PaymentContractInterface $contract): bool
    {
        if ($contract->getState()->isFulfilled()) {
            return false;
        }

        if (!$contract->getState()->isCommitted()) {
            return false;
        }

        return true;
    }

    /**
     * Get reason why contract cannot be fulfilled.
     */
    private function getCannotFulfillReason(PaymentContractInterface $contract): string
    {
        if ($contract->getState()->isFulfilled()) {
            return 'already_fulfilled';
        }

        if (!$contract->getState()->isCommitted()) {
            return 'not_committed';
        }

        return 'unknown';
    }

    /**
     * Dispatch ContractFulfilledEvent for event handlers.
     */
    private function dispatchFulfilledEvent(PaymentContractInterface $contract): void
    {
        $context = new EventContext([
            'contractId' => $contract->getId(),
            'orderId' => $contract->getOrderId(),
            'providerOrderId' => $contract->getProviderOrderId(),
            'source' => 'fulfillment_service',
        ]);

        $event = new ContractFulfilledEvent(
            $contract,
            $context,
            $contract->getOrderId() ?? ''
        );

        $this->eventDispatcher->dispatch($event);
    }
}
