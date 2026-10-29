<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Application\Model\OrderArticle;
use RuntimeException;

/**
 * Core's own restock: OrderArticle::cancelOrderArticle() stornos the line and
 * updates the article stock once (a cancelled line is skipped).
 *
 * @since OPC-175
 */
class OxidOrderRestock implements OrderRestockInterface
{
    public function restock(string $orderId): void
    {
        $order = $this->loadOrder($orderId);

        /** @var OrderArticle $orderArticle */
        foreach ($order->getOrderArticles() as $orderArticle) {
            $orderArticle->cancelOrderArticle();
        }
    }

    /**
     * Protected: an oxNew seam for the unit test.
     */
    protected function loadOrder(string $orderId): Order
    {
        /** @var Order $order */
        $order = oxNew(Order::class);
        if (!$order->load($orderId)) {
            throw new RuntimeException('Order to restock not found: ' . $orderId);
        }

        return $order;
    }
}
