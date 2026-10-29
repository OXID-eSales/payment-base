<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter;

use OxidEsales\PaymentBase\Adapter\Request\CreateOrderRequest;
use OxidEsales\PaymentBase\Adapter\Response\OrderResponse;
use OxidEsales\PaymentBase\Adapter\Exception\ShopOrderException;

/**
 * Rewrites the early order of the attempt a customer already has open.
 *
 * OPC-175: one order, one contract. When the customer changes the payment
 * method or the basket, the same order continues: same id, same order number;
 * payment, lines, totals, stock and vouchers follow the current basket.
 *
 * Separate from ShopOrderServiceInterface (ISP): only payment-base's order
 * service rewrites orders.
 *
 * @since OPC-175
 */
interface ShopOrderRewriterInterface
{
    /**
     * @throws ShopOrderException when the order is not an open (NOT_FINISHED, not stornoed) order,
     *                            or core refuses the basket
     */
    public function rewriteOrder(string $orderId, CreateOrderRequest $request): OrderResponse;
}
