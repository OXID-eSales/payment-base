<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Service\Exception;

use RuntimeException;

/**
 * Exception thrown when there is insufficient stock to fulfill a reservation.
 *
 * Sprint 2: Used by StockService when contract creation should fail
 * due to unavailable items.
 *
 * @since 1.0.0
 */
class InsufficientStockException extends RuntimeException
{
    /**
     * @param string $productId Product that has insufficient stock
     * @param int $requested Quantity requested
     * @param int $available Quantity available
     */
    public function __construct(
        private readonly string $productId,
        private readonly int $requested,
        private readonly int $available
    ) {
        parent::__construct(
            sprintf(
                'Insufficient stock for product %s: requested %d, available %d',
                $productId,
                $requested,
                $available
            )
        );
    }

    public function getProductId(): string
    {
        return $this->productId;
    }

    public function getRequested(): int
    {
        return $this->requested;
    }

    public function getAvailable(): int
    {
        return $this->available;
    }
}
