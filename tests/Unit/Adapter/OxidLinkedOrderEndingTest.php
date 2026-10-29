<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Adapter;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Core\Field;
use OxidEsales\PaymentBase\Adapter\LinkedOrderEndingInterface;
use OxidEsales\PaymentBase\Adapter\OxidLinkedOrderEnding;
use PHPUnit\Framework\TestCase;

/**
 * OPC-175 / S4 — real stock and vouchers: payment-base Integration
 * OxidOrderRestockTest, mollie LinkedOrderEndReleasesVoucherTest.
 */
final class OxidLinkedOrderEndingTest extends TestCase
{
    public function testEnd_UnpaidOrder_IsCancelledTheShopsWayWithTheStatus(): void
    {
        $order = new EndableOrder('0000-00-00 00:00:00');

        $this->ending($order)->end('order-1', LinkedOrderEndingInterface::STATUS_FAILED);

        self::assertSame([1, 0], [$order->cancels, $order->saves]);
        self::assertSame('FAILED', $order->oxorder__oxtransstatus->value);
    }

    public function testEnd_PaidOrder_RecordsTheStatusOnly(): void
    {
        $order = new EndableOrder('2026-10-29 10:00:00');

        $this->ending($order)->end('order-1', LinkedOrderEndingInterface::STATUS_CANCELLED);

        self::assertSame([0, 1], [$order->cancels, $order->saves]);
        self::assertSame('CANCELLED', $order->oxorder__oxtransstatus->value);
    }

    public function testEnd_MissingOrder_ChangesNothing(): void
    {
        $this->expectNotToPerformAssertions();

        $this->ending(null)->end('gone', LinkedOrderEndingInterface::STATUS_CANCELLED);
    }

    private function ending(?Order $order): OxidLinkedOrderEnding
    {
        return new class ($order) extends OxidLinkedOrderEnding {
            public function __construct(private readonly ?Order $order)
            {
            }

            protected function loadOrder(string $orderId): ?Order
            {
                return $this->order;
            }
        };
    }
}

/**
 * Counts the two ways an order is written; core's own cancelOrder() is not in
 * the unit bootstrap's Order.
 */
class EndableOrder extends Order
{
    public int $cancels = 0;
    public int $saves = 0;

    public function __construct(string $paid)
    {
        $this->oxorder__oxpaid = new Field($paid, Field::T_RAW);
    }

    public function save(): mixed
    {
        $this->saves++;

        return true;
    }

    /** @return mixed */
    public function cancelOrder()
    {
        $this->cancels++;

        return null;
    }
}
