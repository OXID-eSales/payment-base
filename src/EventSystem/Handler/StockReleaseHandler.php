<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem\Handler;

use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractCancelledEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractExpiredEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractFailedEvent;
use OxidEsales\PaymentComponent\Service\StockServiceInterface;

/**
 * Handles stock release on contract terminal states (except FULFILLED).
 *
 * Sprint 2: Releases stock via contract-aware StockServiceInterface.
 * Stock is incremented back in OXARTICLES.OXSTOCK when contract reaches
 * a terminal state that doesn't result in order fulfillment.
 *
 * Listens to:
 * - ContractCancelledEvent: User or system cancellation
 * - ContractExpiredEvent: Timeout/expiration
 * - ContractFailedEvent: Payment declined or error
 *
 * Does NOT release on ContractFulfilledEvent (order was completed, stock stays reserved).
 *
 * Can be disabled via configuration. When disabled, no stock is released
 * (OXID handles stock normally on order creation/cancellation).
 *
 * @since 1.0.0
 */
class StockReleaseHandler implements HandlerInterface
{
    public function __construct(
        private readonly StockServiceInterface $stockService,
        private readonly bool $enabled = true
    ) {
    }

    /**
     * Returns ContractCancelledEvent as the primary handled event class.
     * Note: This handler also handles ContractExpiredEvent and ContractFailedEvent.
     */
    public static function getHandledEventClass(): string
    {
        return ContractCancelledEvent::class;
    }

    public function handle(object $event): void
    {
        // Only handle terminal events (except FULFILLED)
        if (!$this->isTerminalEventForRelease($event)) {
            return;
        }

        if (!$this->enabled) {
            return;
        }

        $contract = $this->getContractFromEvent($event);
        if ($contract === null) {
            return;
        }

        // Release stock for the contract - throws StockReleaseException on failure
        $this->stockService->releaseForContract($contract);
    }

    /**
     * Check if this is a terminal event that should release stock.
     */
    private function isTerminalEventForRelease(object $event): bool
    {
        return $event instanceof ContractCancelledEvent
            || $event instanceof ContractExpiredEvent
            || $event instanceof ContractFailedEvent;
    }

    /**
     * Extract contract from event.
     */
    private function getContractFromEvent(object $event): ?\OxidEsales\PaymentComponent\Contract\PaymentContractInterface
    {
        if ($event instanceof ContractCancelledEvent) {
            return $event->getContract();
        }

        if ($event instanceof ContractExpiredEvent) {
            return $event->getContract();
        }

        if ($event instanceof ContractFailedEvent) {
            return $event->getContract();
        }

        return null;
    }
}
