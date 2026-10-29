<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter;

use OxidEsales\PaymentBase\Adapter\Request\CreateOrderRequest;
use OxidEsales\PaymentBase\Adapter\Response\OrderResponse;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * OPC-175 — one order, one contract. A contract that already has its order
 * (it continues the attempt the customer has open) gets that order written
 * again; a new contract gets a new order. Shared by the early-order steps of
 * all providers (EarlyOrderCreationHandler, PayPalOrderCreationHandler).
 *
 * @since OPC-175
 */
class EarlyOrderWriter implements EarlyOrderWriterInterface
{
    public function __construct(
        private readonly ShopOrderServiceInterface $orderService,
        private readonly ShopOrderRewriterInterface $orderRewriter
    ) {
    }

    public function write(PaymentContractInterface $contract, CreateOrderRequest $request): OrderResponse
    {
        $orderId = $contract->getOrderId();
        if ($orderId === null) {
            return $this->orderService->createOrder($request);
        }

        return $this->orderRewriter->rewriteOrder($orderId, $request);
    }
}
