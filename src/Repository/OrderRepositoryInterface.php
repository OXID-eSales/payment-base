<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Repository;

/**
 * Repository interface for managing orders.
 *
 * This interface defines the contract for order persistence operations.
 * Implementations may use different storage mechanisms (database, in-memory, etc.).
 *
 * @since 1.0.0
 */
interface OrderRepositoryInterface
{
    /**
     * Persist an order to storage.
     *
     * @param object $order Order entity to save
     * @return void
     */
    public function save(object $order): void;

    /**
     * Find an order by its unique identifier.
     *
     * @param int $id Order ID
     * @return object|null Order entity or null if not found
     */
    public function findById(int $id): ?object;

    /**
     * Generate the next available order ID.
     *
     * @return int Next available order ID
     */
    public function generateNextId(): int;

    /**
     * Generate the next order number (human-readable identifier).
     *
     * @return string Next order number
     */
    public function generateNextOrderNumber(): string;
}
