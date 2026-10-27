<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Subscriber;

use OxidEsales\GraphQL\Admin\Order\DataType\AdminOrderChangeGuard;
use OxidEsales\GraphQL\Admin\Order\DataType\OrderChangeKind;
use OxidEsales\PaymentBase\GraphQL\Admin\Subscriber\OrderChangeGuardSubscriber;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\ContractFactory;
use OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support\InMemoryContracts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderChangeGuardSubscriberTest extends TestCase
{
    private InMemoryContracts $contracts;

    protected function setUp(): void
    {
        $this->contracts = new InMemoryContracts();
    }

    public function testSubscribesToTheGuardEventOnly(): void
    {
        self::assertSame(
            [AdminOrderChangeGuard::class => 'onChangeGuard'],
            OrderChangeGuardSubscriber::getSubscribedEvents(),
        );
    }

    /**
     * @return iterable<string, array{OrderChangeKind}>
     */
    public static function changesThatMoveTheTotal(): iterable
    {
        yield 'items' => [OrderChangeKind::Items];
        yield 'payment method' => [OrderChangeKind::Payment];
        yield 'delivery set' => [OrderChangeKind::DeliverySet];
    }

    #[DataProvider('changesThatMoveTheTotal')]
    public function testOnChangeGuard_AfterCapture_RefusesChangesThatRecalculateTheTotal(OrderChangeKind $change): void
    {
        $this->contracts->save(ContractFactory::fulfilled('order-1'));
        $guard = $this->guard('order-1', $change);

        $this->subscriber()->onChangeGuard($guard);

        self::assertTrue($guard->isRefused());
        self::assertStringContainsString('captured', $guard->reason());
        self::assertStringContainsString('refund', $guard->reason());
    }

    public function testOnChangeGuard_AfterCapture_AllowsAnAddressCorrection(): void
    {
        $this->contracts->save(ContractFactory::fulfilled('order-1'));
        $guard = $this->guard('order-1', OrderChangeKind::Addresses);

        $this->subscriber()->onChangeGuard($guard);

        self::assertFalse($guard->isRefused());
    }

    public function testOnChangeGuard_PartlyCapturedAndRefunded_StillRefusesItemChanges(): void
    {
        $this->contracts->save(ContractFactory::fulfilled('order-1', 'mollie', 100.0, 100.0, 100.0));
        $guard = $this->guard('order-1', OrderChangeKind::Items);

        $this->subscriber()->onChangeGuard($guard);

        self::assertTrue($guard->isRefused(), 'a refunded payment was still taken: the refund, not the order, settles it');
    }

    #[DataProvider('changesThatMoveTheTotal')]
    public function testOnChangeGuard_BeforeCapture_AllowsTheChange(OrderChangeKind $change): void
    {
        $this->contracts->save(ContractFactory::authorized('order-1'));
        $guard = $this->guard('order-1', $change);

        $this->subscriber()->onChangeGuard($guard);

        self::assertFalse($guard->isRefused());
    }

    public function testOnChangeGuard_OrderWithoutAContract_IsNotOurs_AllowsTheChange(): void
    {
        $guard = $this->guard('order-invoice', OrderChangeKind::Items);

        $this->subscriber()->onChangeGuard($guard);

        self::assertFalse($guard->isRefused());
    }

    public function testOnChangeGuard_AnEarlierRefusalStays(): void
    {
        $this->contracts->save(ContractFactory::fulfilled('order-1'));
        $guard = $this->guard('order-1', OrderChangeKind::Items);
        $guard->refuse('first');

        $this->subscriber()->onChangeGuard($guard);

        self::assertSame('first', $guard->reason());
    }

    private function subscriber(): OrderChangeGuardSubscriber
    {
        return new OrderChangeGuardSubscriber($this->contracts);
    }

    private function guard(string $orderId, OrderChangeKind $change): AdminOrderChangeGuard
    {
        return new AdminOrderChangeGuard($orderId, 1, 'oe_payments_paypal', 'tx-1', $change);
    }
}
