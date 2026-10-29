<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Core\Field;

/**
 * Core cancelOrder() stornos the order and returns the stock; payment-base's
 * Order extension releases the vouchers and drops the waiting order mails.
 * Before OPC-175 the Mollie webhook set only the status (the order kept the
 * coupon and the stock for good, and the cleanup command - NOT_FINISHED only -
 * never saw it again) and Stripe stornoed without restock.
 *
 * @since OPC-175
 */
class OxidLinkedOrderEnding implements LinkedOrderEndingInterface
{
    private const NOT_PAID = '0000-00-00 00:00:00';

    public function end(string $orderId, string $transStatus): void
    {
        $order = $this->loadOrder($orderId);
        if ($order === null) {
            return;
        }

        $order->oxorder__oxtransstatus = new Field($transStatus, Field::T_RAW);
        if ($this->isPaid($order)) {
            $order->save();

            return;
        }

        // Saves the status with the storno.
        $order->cancelOrder();
    }

    /**
     * Protected: an oxNew seam for the unit test.
     */
    protected function loadOrder(string $orderId): ?Order
    {
        /** @var Order $order */
        $order = oxNew(Order::class);

        return $orderId !== '' && $order->load($orderId) ? $order : null;
    }

    private function isPaid(Order $order): bool
    {
        /** @var Field $paid */
        $paid = $order->oxorder__oxpaid;
        $paid = (string) $paid->value;

        return $paid !== '' && $paid !== self::NOT_PAID;
    }
}
