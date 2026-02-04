<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Adapter\Request;

/**
 * Request DTO for creating an order.
 *
 * Contains all necessary information to create and finalize an order
 * from the current basket/cart.
 *
 * Note: This is a provider-agnostic DTO. Basket retrieval should be handled
 * by the shop-specific implementation of ShopOrderServiceInterface.
 *
 * @since 1.0.0
 */
readonly class CreateOrderRequest
{
    /**
     * @param string $sessionId Session identifier for retrieving basket
     * @param string $userId User/Customer identifier
     * @param string $paymentId Payment method identifier
     * @param string|null $paymentTransactionId External payment transaction ID (e.g., PaymentIntent ID)
     * @param string|null $orderRemark Customer's order remark/comment
     * @param array<string, mixed> $metadata Additional metadata to store with order
     */
    public function __construct(
        public string $sessionId,
        public string $userId,
        public string $paymentId,
        public ?string $paymentTransactionId = null,
        public ?string $orderRemark = null,
        public array $metadata = []
    ) {
    }
}
