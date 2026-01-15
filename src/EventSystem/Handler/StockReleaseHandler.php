<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem\Handler;

use OxidEsales\PaymentComponent\Contract\ContractCondition;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractCancelledEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractFailedEvent;
use OxidEsales\PaymentComponent\Service\StockManagementServiceInterface;

/**
 * Handles stock release on contract failure or cancellation.
 *
 * When a payment contract fails or is cancelled, this handler releases
 * any stock that was previously reserved during payment initiation.
 *
 * Listens to:
 * - ContractFailedEvent: Payment declined, timeout, or error
 * - ContractCancelledEvent: User cancelled the payment
 *
 * @since 1.0.0
 */
class StockReleaseHandler implements HandlerInterface
{
    public function __construct(
        private StockManagementServiceInterface $stockManagement
    ) {
    }

    /**
     * Returns ContractFailedEvent as the primary handled event class.
     * Note: This handler also handles ContractCancelledEvent via the handle() method check.
     */
    public static function getHandledEventClass(): string
    {
        return ContractFailedEvent::class;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof ContractFailedEvent && !$event instanceof ContractCancelledEvent) {
            return;
        }

        $contract = $event->getContract();

        // Find the stock reservation condition
        $stockCondition = null;
        foreach ($contract->getConditions() as $condition) {
            if ($condition->getType() === ContractCondition::TYPE_STOCK_RESERVED) {
                $stockCondition = $condition;
                break;
            }
        }

        if ($stockCondition === null) {
            return;
        }

        $data = $stockCondition->getData();
        if (!is_array($data) || !isset($data['products']) || !is_array($data['products'])) {
            return;
        }

        /** @var array<array{productId: string, quantity: int}> $products */
        $products = $data['products'];

        if (empty($products)) {
            return;
        }

        // Release stock for each product
        foreach ($products as $product) {
            $this->stockManagement->releaseStock(
                $product['productId'],
                $product['quantity']
            );
        }
    }
}
