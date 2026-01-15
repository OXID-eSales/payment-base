<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Adapter\Response;

use DateTimeImmutable;

/**
 * Response DTO containing created order details.
 *
 * Platform-agnostic representation of an order.
 *
 * @since 1.0.0
 */
final readonly class OrderResponse
{
    /**
     * @param string $orderId Shop-internal order ID
     * @param int $orderNumber Customer-visible order number
     * @param string $userId User/Customer identifier
     * @param float $totalAmount Total order amount
     * @param string $currency Currency code (ISO 4217)
     * @param string $status Order status (pending, processing, completed, cancelled, etc.)
     * @param string $paymentId Payment method identifier
     * @param string|null $paymentTransactionId External payment transaction ID
     * @param DateTimeImmutable|null $createdAt Order creation timestamp
     * @param array<string, mixed> $metadata Additional order metadata
     * @param array<string, mixed> $shopData Platform-specific order data (for debugging/logging)
     */
    public function __construct(
        public string $orderId,
        public int $orderNumber,
        public string $userId,
        public float $totalAmount,
        public string $currency,
        public string $status,
        public string $paymentId,
        public ?string $paymentTransactionId = null,
        public ?DateTimeImmutable $createdAt = null,
        public array $metadata = [],
        public array $shopData = []
    ) {
    }
}
