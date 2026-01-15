<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\EventSystem\Handler;

use OxidEsales\PaymentComponent\Contract\ContractCondition;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\PaymentInitiatedEvent;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Service\StockManagementServiceInterface;
use DateTimeImmutable;
use RuntimeException;

/**
 * Handles stock reservation on payment initiation.
 *
 * Reserves stock for all products in the basket when payment is initiated.
 * Stock is temporarily reserved for 15 minutes to prevent overselling during
 * the payment process.
 *
 * On success: Fulfills TYPE_STOCK_RESERVED condition
 * On failure: Fails the contract with stock error message
 *
 * @since 1.0.0
 */
class StockReservationHandler implements HandlerInterface
{
    private const RESERVATION_TIMEOUT_SECONDS = 900; // 15 minutes

    public function __construct(
        private ContractRepositoryInterface $contractRepository,
        private StockManagementServiceInterface $stockManagement
    ) {
    }

    public static function getHandledEventClass(): string
    {
        return PaymentInitiatedEvent::class;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof PaymentInitiatedEvent) {
            return;
        }

        $context = $event->getContext();
        $contract = $context->get('contract');
        $basket = $context->get('basket');

        if (!$contract instanceof PaymentContractInterface || !is_iterable($basket)) {
            return;
        }

        try {
            $reservedProducts = [];

            /** @var array{productId: string, quantity: int} $item */
            foreach ($basket as $item) {
                $productId = $item['productId'];
                $quantity = $item['quantity'];

                $this->stockManagement->reserveStock(
                    $productId,
                    $quantity,
                    self::RESERVATION_TIMEOUT_SECONDS
                );

                $reservedProducts[] = [
                    'productId' => $productId,
                    'quantity' => $quantity,
                ];
            }

            // Fulfill stock reservation condition
            $contract->fulfillCondition(
                ContractCondition::TYPE_STOCK_RESERVED,
                [
                    'reservedAt' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                    'products' => $reservedProducts,
                    'timeoutSeconds' => self::RESERVATION_TIMEOUT_SECONDS,
                ]
            );
        } catch (RuntimeException $e) {
            // Insufficient stock: Fail the contract
            $contract->fail('Stock reservation failed: ' . $e->getMessage());
        }

        $this->contractRepository->save($contract);
    }
}
