<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Order;

use DateTimeInterface;

/**
 * Interface for order data representation.
 *
 * SOLID Principles:
 * - SRP: Single responsibility - order data contract
 * - ISP: Minimal interface with essential methods only
 * - LSP: Any implementation must satisfy this contract
 *
 * @since 1.0.0
 */
interface OrderInterface
{
    /**
     * Get the order's unique identifier.
     */
    public function getId(): int;

    /**
     * Get the human-readable order number.
     */
    public function getOrderNumber(): string;

    /**
     * Get the user ID who placed the order.
     */
    public function getUserId(): string;

    /**
     * Get the total gross amount.
     */
    public function getTotalGross(): float;

    /**
     * Get the total net amount.
     */
    public function getTotalNet(): float;

    /**
     * Get the total VAT amount.
     */
    public function getTotalVat(): float;

    /**
     * Get the currency code.
     */
    public function getCurrency(): string;

    /**
     * Get the order items.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getItems(): array;

    /**
     * Get the associated contract ID.
     */
    public function getContractId(): string;

    /**
     * Get the order creation timestamp.
     */
    public function getCreatedAt(): DateTimeInterface;

    /**
     * Get the order status.
     */
    public function getStatus(): string;
}
