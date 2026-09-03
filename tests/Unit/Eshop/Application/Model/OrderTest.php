<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Eshop\Application\Model;

use OxidEsales\PaymentBase\Eshop\Application\Model\Order;
use OxidEsales\PaymentBase\Eshop\Application\Model\Order_parent;
use OxidEsales\PaymentBase\Repository\VoucherReleaseInterface;
use OxidEsales\PaymentBase\Service\VoucherReleaseSettingsInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Sprint 09 — an order that ends returns its vouchers to the pool.
 *
 * Core does neither: cancelOrder() sets oxstorno, saves and restocks the
 * articles; delete() removes the order articles and the payment row. The coupon
 * stays stamped with an order that was called off, or - worse, after a delete -
 * with an order that is no longer in oxorder at all, where no later action can
 * find it.
 */
final class SpyVoucherRelease implements VoucherReleaseInterface
{
    /** @var array<int, string> */
    public array $released = [];

    public function __construct(private readonly ?RuntimeException $failWith = null)
    {
    }

    public function releaseVouchers(string $orderId): int
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        Order_parent::$calls[] = 'release';
        $this->released[] = $orderId;

        return 1;
    }
}

final class FixedVoucherReleaseSettings implements VoucherReleaseSettingsInterface
{
    public function __construct(private readonly bool $enabled)
    {
    }

    public function isReleaseOnOrderEndEnabled(): bool
    {
        return $this->enabled;
    }
}

final class TestableOrder extends Order
{
    public function __construct(
        private readonly VoucherReleaseInterface $release,
        private readonly VoucherReleaseSettingsInterface $settings,
    ) {
        // Deliberately no parent::__construct(): OXID's BaseModel bootstrap
        // needs a shop, and none of it is under test here.
    }

    protected function getVoucherRelease(): VoucherReleaseInterface
    {
        return $this->release;
    }

    protected function getVoucherReleaseSettings(): VoucherReleaseSettingsInterface
    {
        return $this->settings;
    }
}

final class OrderTest extends TestCase
{
    protected function setUp(): void
    {
        Order_parent::$calls = [];
        Order_parent::$deleteResult = true;
    }

    private function order(
        VoucherReleaseInterface $release,
        bool $enabled = true
    ): TestableOrder {
        return new TestableOrder($release, new FixedVoucherReleaseSettings($enabled));
    }

    public function testDeletingAnOrderReturnsItsVouchers(): void
    {
        $release = new SpyVoucherRelease();

        $this->order($release)->delete();

        self::assertSame(['order-1'], $release->released);
    }

    public function testTheVouchersAreReleasedBeforeTheOrderRowIsGone(): void
    {
        // The whole point of the ordering: oxvouchers is joined by OXORDERID, so
        // after parent::delete() the row could never be found again.
        $release = new SpyVoucherRelease();

        $this->order($release)->delete();

        self::assertSame(['release', 'parent::delete'], Order_parent::$calls);
    }

    public function testDeleteStillReportsWhatCoreReported(): void
    {
        Order_parent::$deleteResult = false;

        self::assertFalse($this->order(new SpyVoucherRelease())->delete());
    }

    public function testCancellingAnOrderReturnsItsVouchers(): void
    {
        $release = new SpyVoucherRelease();

        $this->order($release)->cancelOrder();

        self::assertSame(['order-1'], $release->released);
    }

    public function testCancelReleasesOnlyAfterCoreAcceptedTheStorno(): void
    {
        // Core restocks the articles only if its save() worked; the coupon
        // follows the same signal instead of inventing its own.
        $release = new SpyVoucherRelease();

        $this->order($release)->cancelOrder();

        self::assertSame(['parent::cancelOrder', 'release'], Order_parent::$calls);
    }

    public function testTheKillSwitchLeavesCoreBehaviourUntouched(): void
    {
        $release = new SpyVoucherRelease();

        $this->order($release, enabled: false)->delete();

        self::assertSame([], $release->released);
        self::assertSame(['parent::delete'], Order_parent::$calls);
    }

    public function testAFailedReleaseNeverBlocksTheMerchant(): void
    {
        // A coupon that failed to come back is a support ticket. An admin
        // delete that throws is an outage.
        $order = $this->order(new SpyVoucherRelease(new RuntimeException('db gone')));

        self::assertTrue($order->delete());
        self::assertSame(['parent::delete'], Order_parent::$calls);
    }

    public function testAFailedReleaseNeverBlocksAStorno(): void
    {
        $order = $this->order(new SpyVoucherRelease(new RuntimeException('db gone')));

        $order->cancelOrder();

        self::assertSame(['parent::cancelOrder'], Order_parent::$calls);
    }
}
