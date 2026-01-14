<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Service;

/**
 * Service for managing stock reservations.
 *
 * Handles temporary stock reservations during payment processing
 * to prevent overselling. Reservations automatically expire after
 * a timeout period.
 *
 * @since 1.0.0
 */
interface StockManagementServiceInterface
{
    /**
     * Reserve stock for a product.
     *
     * @param string $productId Product identifier
     * @param int $quantity Quantity to reserve
     * @param int $timeoutSeconds Reservation timeout in seconds (default: 900 = 15 minutes)
     * @return void
     * @throws \RuntimeException If insufficient stock available
     */
    public function reserveStock(string $productId, int $quantity, int $timeoutSeconds = 900): void;

    /**
     * Release reserved stock for a product.
     *
     * @param string $productId Product identifier
     * @param int $quantity Quantity to release
     * @return void
     */
    public function releaseStock(string $productId, int $quantity): void;

    /**
     * Check if sufficient stock is available.
     *
     * @param string $productId Product identifier
     * @param int $quantity Required quantity
     * @return bool True if sufficient stock, false otherwise
     */
    public function hasAvailableStock(string $productId, int $quantity): bool;
}
