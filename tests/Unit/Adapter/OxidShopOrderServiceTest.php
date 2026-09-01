<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Adapter;

use OxidEsales\PaymentBase\Adapter\OxidShopOrderService;
use OxidEsales\PaymentBase\Adapter\ShopOrderServiceInterface;
use OxidEsales\PaymentBase\Repository\NotFinishedOrderRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * Records what the canonical adapter asked the repository to do.
 */
class SpyOrderRepository implements NotFinishedOrderRepositoryInterface
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(private readonly bool $cancelSucceeds = true)
    {
    }

    public function findStaleNotFinishedOrderIds(int $days, ?int $shopId = null, ?int $limit = null): array
    {
        return [];
    }

    public function cancelOrder(string $orderId): bool
    {
        $this->calls[] = "cancel:$orderId";

        return $this->cancelSucceeds;
    }

    public function releaseVouchers(string $orderId): int
    {
        $this->calls[] = "vouchers:$orderId";

        return 1;
    }
}

/**
 * payment-base owns order finalization.
 *
 * Every PSP module used to ship its own copy of this adapter and alias the
 * shared `ShopOrderServiceInterface` to it. One container id means one winner,
 * so whichever module's services.yaml merged last finalized *every* provider's
 * orders — on one installation Stripe's copy was finalizing Mollie payments.
 *
 * createOrder() needs the shop and is covered by the integration suite; the
 * cancellation path is pure delegation and is pinned here.
 */
final class OxidShopOrderServiceTest extends TestCase
{
    public function testImplementsTheSharedContract(): void
    {
        $this->assertInstanceOf(
            ShopOrderServiceInterface::class,
            new OxidShopOrderService(new SpyOrderRepository())
        );
    }

    /**
     * Cancelling is the same storno + voucher release the cleanup command does,
     * written once rather than copied into each provider's adapter.
     */
    public function testCancellingStornosTheOrderThenReleasesItsVouchers(): void
    {
        $repository = new SpyOrderRepository();

        $this->assertTrue((new OxidShopOrderService($repository))->deleteNotFinishedOrder('order-1'));
        $this->assertSame(['cancel:order-1', 'vouchers:order-1'], $repository->calls);
    }

    /**
     * The write is guarded on the order still being NOT_FINISHED. When it no
     * longer is, the vouchers belong to a live order and must not be handed
     * back — so the guard's answer decides, not the caller.
     */
    public function testAnOrderThatNoLongerQualifiesKeepsItsVouchers(): void
    {
        $repository = new SpyOrderRepository(cancelSucceeds: false);

        $this->assertFalse((new OxidShopOrderService($repository))->deleteNotFinishedOrder('order-1'));
        $this->assertSame(['cancel:order-1'], $repository->calls, 'vouchers must not be released');
    }
}
