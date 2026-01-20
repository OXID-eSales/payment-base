<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem\Handler;

use DateTimeImmutable;
use OxidEsales\PaymentComponent\Contract\ContractCondition;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractCreatedEvent;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Service\Exception\InsufficientStockException;
use OxidEsales\PaymentComponent\Service\StockServiceInterface;

/**
 * Handles stock reservation on contract creation (DRAFT state).
 *
 * Sprint 2: Reserves stock via contract-aware StockServiceInterface.
 * Stock is decremented directly in OXARTICLES.OXSTOCK when contract is created,
 * before Stripe redirect. If insufficient stock, contract creation fails.
 *
 * On success: Fulfills TYPE_STOCK_RESERVED condition
 * On failure: Fails the contract with stock error message
 *
 * Can be disabled via configuration. When disabled, the condition is
 * immediately fulfilled without reserving stock (OXID handles stock normally).
 *
 * @since 1.0.0
 */
class StockReservationHandler implements HandlerInterface
{
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly StockServiceInterface $stockService,
        private readonly bool $enabled = true
    ) {
    }

    public static function getHandledEventClass(): string
    {
        return ContractCreatedEvent::class;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof ContractCreatedEvent) {
            return;
        }

        $contract = $event->getContract();

        if (!$this->enabled) {
            // When disabled, immediately fulfill condition without reserving stock
            $contract->fulfillCondition(
                ContractCondition::TYPE_STOCK_RESERVED,
                [
                    'skipped' => true,
                    'reason' => 'Stock reservation disabled in configuration',
                ]
            );
            $this->contractRepository->save($contract);
            return;
        }

        try {
            // Reserve stock for all items in the contract
            $this->stockService->reserveForContract($contract);

            // Fulfill stock reservation condition
            $contract->fulfillCondition(
                ContractCondition::TYPE_STOCK_RESERVED,
                [
                    'reservedAt' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                ]
            );
        } catch (InsufficientStockException $e) {
            // Insufficient stock: Fail the contract
            $contract->fail(sprintf(
                'Insufficient stock for product %s (requested: %d, available: %d)',
                $e->getProductId(),
                $e->getRequested(),
                $e->getAvailable()
            ));
        }

        $this->contractRepository->save($contract);
    }
}
