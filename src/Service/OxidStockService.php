<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Service;

use Doctrine\DBAL\Connection;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\Service\Exception\InsufficientStockException;
use OxidEsales\PaymentComponent\Service\Exception\StockReleaseException;
use Throwable;

/**
 * OXID implementation of stock service.
 *
 * Sprint 2: Manipulates OXARTICLES.OXSTOCK directly (no tracking table).
 * Extracts items from contract's basket snapshot and reserves/releases stock.
 *
 * @since 1.0.0
 */
class OxidStockService implements StockServiceInterface
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * @inheritDoc
     */
    public function reserveForContract(PaymentContractInterface $contract): void
    {
        $items = $this->extractItemsFromContract($contract);

        if (empty($items)) {
            return;
        }

        // First check all items have sufficient stock
        foreach ($items as $productId => $quantity) {
            $available = $this->getAvailableStock($productId);
            if ($available < $quantity) {
                throw new InsufficientStockException($productId, $quantity, $available);
            }
        }

        // Then decrement stock for all items
        foreach ($items as $productId => $quantity) {
            $this->decrementStock($productId, $quantity);
        }

        // Mark contract as having reserved stock
        $contract->setMetadata('stock_reserved', true);
        $contract->setMetadata('stock_reserved_items', $items);
    }

    /**
     * @inheritDoc
     */
    public function releaseForContract(PaymentContractInterface $contract): void
    {
        // Check if stock was reserved for this contract
        $stockReserved = $contract->getMetadata('stock_reserved');
        if ($stockReserved !== true) {
            return; // No stock was reserved, nothing to release
        }

        /** @var array<string, int>|null $items */
        $items = $contract->getMetadata('stock_reserved_items');
        if (!is_array($items) || empty($items)) {
            return;
        }

        try {
            // Increment stock for all items
            foreach ($items as $productId => $quantity) {
                $this->incrementStock((string) $productId, (int) $quantity);
            }

            // Clear the reservation metadata
            $contract->setMetadata('stock_reserved', false);
            $contract->setMetadata('stock_reserved_items', null);
        } catch (Throwable $e) {
            throw new StockReleaseException(
                $contract->getId() ?? 'unknown',
                $e->getMessage(),
                $e
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function hasAvailableStock(PaymentContractInterface $contract): bool
    {
        $items = $this->extractItemsFromContract($contract);

        foreach ($items as $productId => $quantity) {
            $available = $this->getAvailableStock($productId);
            if ($available < $quantity) {
                return false;
            }
        }

        return true;
    }

    /**
     * Extract items from contract's basket snapshot.
     *
     * @param PaymentContractInterface $contract
     * @return array<string, int> [productId => quantity]
     */
    private function extractItemsFromContract(PaymentContractInterface $contract): array
    {
        $basketSnapshot = $contract->getBasketSnapshot();
        $items = [];

        $basketItems = $basketSnapshot->getItems();
        foreach ($basketItems as $item) {
            $productId = $item['productId'] ?? $item['articleId'] ?? null;
            $quantity = (int) ($item['quantity'] ?? $item['amount'] ?? 0);

            if ($productId !== null && $quantity > 0) {
                $productId = (string) $productId;
                $items[$productId] = ($items[$productId] ?? 0) + $quantity;
            }
        }

        return $items;
    }

    /**
     * Get available stock for a product.
     *
     * @param string $productId OXID article ID
     * @return int Available stock
     */
    private function getAvailableStock(string $productId): int
    {
        $sql = 'SELECT OXSTOCK FROM oxarticles WHERE OXID = :productId';
        $result = $this->connection->fetchOne($sql, ['productId' => $productId]);

        return (int) ($result ?: 0);
    }

    /**
     * Decrement stock for a product.
     *
     * @param string $productId OXID article ID
     * @param int $quantity Quantity to decrement
     */
    private function decrementStock(string $productId, int $quantity): void
    {
        $sql = 'UPDATE oxarticles SET OXSTOCK = OXSTOCK - :quantity WHERE OXID = :productId';
        $this->connection->executeStatement($sql, [
            'quantity' => $quantity,
            'productId' => $productId,
        ]);
    }

    /**
     * Increment stock for a product.
     *
     * @param string $productId OXID article ID
     * @param int $quantity Quantity to increment
     */
    private function incrementStock(string $productId, int $quantity): void
    {
        $sql = 'UPDATE oxarticles SET OXSTOCK = OXSTOCK + :quantity WHERE OXID = :productId';
        $this->connection->executeStatement($sql, [
            'quantity' => $quantity,
            'productId' => $productId,
        ]);
    }
}
