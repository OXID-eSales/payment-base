<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Service;

use RuntimeException;

/**
 * In-memory implementation of stock management service.
 *
 * This is a simplified implementation for the payment component.
 * In production, this should integrate with the OXID inventory system.
 *
 * @since 1.0.0
 */
class StockManagementService implements StockManagementServiceInterface
{
    /**
     * @var array<string, array{quantity: int, expires: int}> Reserved stock by product ID
     */
    private array $reservations = [];

    /**
     * @var array<string, int> Available stock by product ID (simulated)
     */
    private array $availableStock = [];

    public function reserveStock(string $productId, int $quantity, int $timeoutSeconds = 900): void
    {
        $this->cleanExpiredReservations();

        if (!$this->hasAvailableStock($productId, $quantity)) {
            throw new RuntimeException(
                sprintf('Insufficient stock for product %s (requested: %d)', $productId, $quantity)
            );
        }

        $expiresAt = time() + $timeoutSeconds;

        if (!isset($this->reservations[$productId])) {
            $this->reservations[$productId] = ['quantity' => 0, 'expires' => $expiresAt];
        }

        $this->reservations[$productId]['quantity'] += $quantity;
        $this->reservations[$productId]['expires'] = max(
            $this->reservations[$productId]['expires'],
            $expiresAt
        );
    }

    public function releaseStock(string $productId, int $quantity): void
    {
        if (!isset($this->reservations[$productId])) {
            return;
        }

        $this->reservations[$productId]['quantity'] -= $quantity;

        if ($this->reservations[$productId]['quantity'] <= 0) {
            unset($this->reservations[$productId]);
        }
    }

    public function hasAvailableStock(string $productId, int $quantity): bool
    {
        $this->cleanExpiredReservations();

        $available = $this->getAvailableStock($productId);
        $reserved = $this->getReservedStock($productId);

        return ($available - $reserved) >= $quantity;
    }

    /**
     * Get available stock for a product (simulated).
     *
     * In production, this would query the OXID inventory system.
     *
     * @param string $productId Product identifier
     * @return int Available stock quantity
     */
    private function getAvailableStock(string $productId): int
    {
        return $this->availableStock[$productId] ?? 100; // Default: 100 units
    }

    /**
     * Get currently reserved stock for a product.
     *
     * @param string $productId Product identifier
     * @return int Reserved stock quantity
     */
    private function getReservedStock(string $productId): int
    {
        if (!isset($this->reservations[$productId])) {
            return 0;
        }

        return $this->reservations[$productId]['quantity'];
    }

    /**
     * Clean up expired reservations.
     *
     * @return void
     */
    private function cleanExpiredReservations(): void
    {
        $now = time();

        foreach ($this->reservations as $productId => $reservation) {
            if ($reservation['expires'] < $now) {
                unset($this->reservations[$productId]);
            }
        }
    }

    /**
     * Set available stock for a product (for testing).
     *
     * @param string $productId Product identifier
     * @param int $quantity Available quantity
     * @return void
     * @internal
     */
    public function setAvailableStock(string $productId, int $quantity): void
    {
        $this->availableStock[$productId] = $quantity;
    }
}
