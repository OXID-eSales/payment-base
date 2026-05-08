<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter;

use OxidEsales\PaymentBase\Adapter\Request\CreateOrderRequest;
use OxidEsales\PaymentBase\Adapter\Response\OrderResponse;

/**
 * Shop-agnostic order service interface.
 *
 * Provides abstraction for shop-specific order operations.
 * Platform-specific implementations (OXID, Shopware, etc.) implement this interface.
 *
 * Phase 1: Order Creation
 * - Creating orders from baskets/carts
 * - Finalizing orders
 * - Setting initial order status
 *
 * @since 1.0.0
 */
interface ShopOrderServiceInterface
{
    /**
     * Create and finalize an order from the current basket/cart.
     *
     * This method handles the complete order creation process:
     * - Validates basket/cart
     * - Creates order record
     * - Reserves stock
     * - Sets initial order status
     * - Triggers order creation events
     *
     * @param CreateOrderRequest $request Order creation parameters
     * @return OrderResponse Created order details
     * @throws \OxidEsales\PaymentBase\Adapter\Exception\ShopOrderException
     */
    public function createOrder(CreateOrderRequest $request): OrderResponse;

    /**
     * Delete a NOT_FINISHED order.
     *
     * Only deletes the order if its status is NOT_FINISHED (early order that was
     * never committed). Returns true if the order was deleted, false otherwise.
     *
     * @param string $orderId The order ID to delete
     * @return bool True if order was deleted, false if not found or not NOT_FINISHED
     */
    public function deleteNotFinishedOrder(string $orderId): bool;
}
