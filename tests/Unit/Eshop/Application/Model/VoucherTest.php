<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Eshop\Application\Model;

use OxidEsales\PaymentBase\Checkout\OpenCheckoutAttemptOrderInterface;
use OxidEsales\PaymentBase\Eshop\Application\Model\Voucher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The extension with its shop seams replaced: the open attempt's order, the
 * series flag, the use count and the refusal.
 */
final class TestableVoucher extends Voucher
{
    public ?string $openOrderId = null;
    public bool $resolverFails = false;
    public bool $seriesAllowsAnother = false;
    public int $usesOutsideTheOpenOrder = 0;
    /** @var list<string> */
    public array $excludedOrders = [];

    public function callIsAvailable(): mixed
    {
        return $this->isAvailable();
    }

    public function callIsAvailableInOtherOrder(): mixed
    {
        return $this->isAvailableInOtherOrder(new \stdClass());
    }

    protected function getOpenCheckoutAttemptOrder(): OpenCheckoutAttemptOrderInterface
    {
        if ($this->resolverFails) {
            throw new RuntimeException('container unavailable');
        }

        $orderId = $this->openOrderId;

        return new class ($orderId) implements OpenCheckoutAttemptOrderInterface {
            public function __construct(private readonly ?string $orderId)
            {
            }

            public function orderId(): ?string
            {
                return $this->orderId;
            }
        };
    }

    protected function seriesAllowsAnotherUse(): bool
    {
        return $this->seriesAllowsAnother;
    }

    protected function countUsesOutsideOrder(string $userId, string $excludedOrderId): int
    {
        $this->excludedOrders[] = $excludedOrderId;

        return $this->usesOutsideTheOpenOrder;
    }

    protected function refuseSecondUseOfSeries(): never
    {
        throw new RuntimeException('extension: ERROR_MESSAGE_VOUCHER_NOTALLOWEDSAMESERIES');
    }
}

/**
 * OPC-175 / S2a — the early order marks the basket voucher as used before the
 * customer pays. Core then refuses that voucher in the next basket
 * recalculation, because its row names an order - the session's own open
 * attempt - and drops the coupon. The extension accepts exactly that row;
 * every other row keeps core's rules.
 */
final class VoucherTest extends TestCase
{
    public function testIsAvailable_RowOfTheOpenAttemptOrder_IsAccepted(): void
    {
        $voucher = $this->voucherUsedBy('order-open');
        $voucher->openOrderId = 'order-open';

        self::assertTrue($voucher->callIsAvailable());
    }

    public function testIsAvailable_RowOfAnotherOrder_KeepsTheCoreRefusal(): void
    {
        $voucher = $this->voucherUsedBy('order-paid-last-week');
        $voucher->openOrderId = 'order-open';

        $this->expectExceptionMessage('core: ERROR_MESSAGE_VOUCHER_NOVOUCHER');
        $voucher->callIsAvailable();
    }

    public function testIsAvailable_NoOpenAttempt_KeepsTheCoreRefusal(): void
    {
        $voucher = $this->voucherUsedBy('order-open');

        $this->expectExceptionMessage('core: ERROR_MESSAGE_VOUCHER_NOVOUCHER');
        $voucher->callIsAvailable();
    }

    public function testIsAvailable_FreeRow_IsAcceptedWithoutAskingForTheOpenAttempt(): void
    {
        $voucher = $this->voucherUsedBy('');
        $voucher->resolverFails = true;

        self::assertTrue($voucher->callIsAvailable());
    }

    public function testIsAvailable_WhenTheOpenAttemptCannotBeRead_Fails(): void
    {
        $voucher = $this->voucherUsedBy('order-open');
        $voucher->resolverFails = true;

        $this->expectExceptionMessage('container unavailable');
        $voucher->callIsAvailable();
    }

    public function testIsAvailableInOtherOrder_NoOpenAttempt_AsksCore(): void
    {
        $voucher = $this->voucherUsedBy('order-open');
        $voucher->usedBefore = true;

        $this->expectExceptionMessage('core: ERROR_MESSAGE_VOUCHER_NOTALLOWEDSAMESERIES');
        $voucher->callIsAvailableInOtherOrder();
    }

    public function testIsAvailableInOtherOrder_SeriesAllowsAnotherUse_IsAccepted(): void
    {
        $voucher = $this->voucherUsedBy('order-open');
        $voucher->openOrderId = 'order-open';
        $voucher->seriesAllowsAnother = true;
        $voucher->usesOutsideTheOpenOrder = 3;

        self::assertTrue($voucher->callIsAvailableInOtherOrder());
    }

    /** A "one per customer" coupon: its only use is the attempt being paid for now. */
    public function testIsAvailableInOtherOrder_OnlyUseIsTheOpenAttemptOrder_IsAccepted(): void
    {
        $voucher = $this->voucherUsedBy('order-open');
        $voucher->openOrderId = 'order-open';

        self::assertTrue($voucher->callIsAvailableInOtherOrder());
        self::assertSame(['order-open'], $voucher->excludedOrders);
    }

    public function testIsAvailableInOtherOrder_UsedInAnEarlierOrder_IsRefused(): void
    {
        $voucher = $this->voucherUsedBy('order-open');
        $voucher->openOrderId = 'order-open';
        $voucher->usesOutsideTheOpenOrder = 1;

        $this->expectExceptionMessage('extension: ERROR_MESSAGE_VOUCHER_NOTALLOWEDSAMESERIES');
        $voucher->callIsAvailableInOtherOrder();
    }

    public function testIsAvailableInOtherOrder_WhenTheOpenAttemptCannotBeRead_Fails(): void
    {
        $voucher = $this->voucherUsedBy('order-open');
        $voucher->resolverFails = true;

        $this->expectExceptionMessage('container unavailable');
        $voucher->callIsAvailableInOtherOrder();
    }

    private function voucherUsedBy(string $orderId): TestableVoucher
    {
        $voucher = new TestableVoucher();
        $voucher->fields['oxorderid'] = $orderId;

        return $voucher;
    }
}
