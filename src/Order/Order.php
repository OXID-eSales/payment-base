<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Order;

use DateTime;
use DateTimeInterface;

/**
 * Order data transfer object.
 *
 * SOLID Principles:
 * - SRP: Contains only order data representation
 * - OCP: Closed for modification after construction (mostly immutable)
 * - LSP: Fully substitutable for OrderInterface
 * - DIP: Depends on abstraction (implements interface)
 *
 * @since 1.0.0
 */
final class Order implements OrderInterface
{
    private string $status;
    private DateTimeInterface $createdAt;

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function __construct(
        private readonly int $id,
        private readonly string $orderNumber,
        private readonly string $userId,
        private readonly float $totalGross,
        private readonly float $totalNet,
        private readonly float $totalVat,
        private readonly string $currency,
        private readonly array $items,
        private readonly string $contractId
    ) {
        $this->status = 'pending';
        $this->createdAt = new DateTime();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getOrderNumber(): string
    {
        return $this->orderNumber;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getTotalGross(): float
    {
        return $this->totalGross;
    }

    public function getTotalNet(): float
    {
        return $this->totalNet;
    }

    public function getTotalVat(): float
    {
        return $this->totalVat;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getItems(): array
    {
        return $this->items;
    }

    public function getContractId(): string
    {
        return $this->contractId;
    }

    public function getCreatedAt(): DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Update the order status.
     *
     * Note: This is the only mutable property to allow status transitions.
     */
    public function setStatus(string $status): void
    {
        $this->status = $status;
    }
}
