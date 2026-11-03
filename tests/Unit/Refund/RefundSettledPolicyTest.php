<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Refund;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Refund\RefundSettledPolicy;
use OxidEsales\PaymentBase\Service\StockRestorationServiceInterface;
use PHPUnit\Framework\TestCase;

/**
 * VR-21 F3 (D-1) — stock comes back once, when the last refundable cent has been refunded and the money
 * has actually moved: never on a partial refund, never on a pending one.
 */
final class RefundSettledPolicyTest extends TestCase
{
    public function testAFullySucceededRefundRestocksTheOrder(): void
    {
        $stock = $this->createMock(StockRestorationServiceInterface::class);
        $stock->expects(self::once())->method('restoreForFullRefund')->with('order-1');

        $restocked = (new RefundSettledPolicy($stock))->settle($this->contract('order-1', fullyRefunded: true), true);

        self::assertTrue($restocked);
    }

    public function testAPartialRefundKeepsTheStock(): void
    {
        $stock = $this->createMock(StockRestorationServiceInterface::class);
        $stock->expects(self::never())->method('restoreForFullRefund');

        $restocked = (new RefundSettledPolicy($stock))->settle($this->contract('order-1', fullyRefunded: false), true);

        self::assertFalse($restocked);
    }

    public function testAPendingRefundKeepsTheStockEvenWhenItCoversTheRest(): void
    {
        $stock = $this->createMock(StockRestorationServiceInterface::class);
        $stock->expects(self::never())->method('restoreForFullRefund');

        $restocked = (new RefundSettledPolicy($stock))->settle($this->contract('order-1', fullyRefunded: true), false);

        self::assertFalse($restocked);
    }

    public function testAContractWithoutAnOrderRestocksNothing(): void
    {
        $stock = $this->createMock(StockRestorationServiceInterface::class);
        $stock->expects(self::never())->method('restoreForFullRefund');

        $restocked = (new RefundSettledPolicy($stock))->settle($this->contract(null, fullyRefunded: true), true);

        self::assertFalse($restocked);
    }

    private function contract(?string $orderId, bool $fullyRefunded): PaymentContractInterface
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getOrderId')->willReturn($orderId);
        $contract->method('isFullyRefunded')->willReturn($fullyRefunded);

        return $contract;
    }
}
