<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Adapter;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\PaymentBase\Adapter\Exception\ShopOrderException;
use OxidEsales\PaymentBase\Adapter\OrderShippingAddressCopier;
use OxidEsales\PaymentBase\Adapter\OxidShopOrderService;
use OxidEsales\PaymentBase\Adapter\Request\CreateOrderRequest;
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

    public function isNotFinished(string $orderId): bool
    {
        return true;
    }
}

/**
 * An Order whose finalizeOrder() answers a chosen core state, and which counts
 * how often the service saved it (MOL-18).
 */
class ScriptedOrder extends Order
{
    public int $saves = 0;

    public function __construct(private readonly int $finalizeState)
    {
    }

    public function finalizeOrder($basket, $user, $recalculating = false): int
    {
        return $this->finalizeState;
    }

    public function save(): mixed
    {
        $this->saves++;

        return true;
    }

    public function getId(): ?string
    {
        return 'order-1';
    }

    public function getFieldData(string $field): mixed
    {
        return match ($field) {
            'oxordernr' => 42,
            'oxorderdate' => '2026-09-24 10:00:00',
            default => '',
        };
    }
}

class FixtureUser extends User
{
    public function getId(): ?string
    {
        return 'user-1';
    }
}

class FixtureBasket extends Basket
{
    public function getBasketUser()
    {
        return new FixtureUser();
    }

    public function getPrice()
    {
        return new class {
            public function getBruttoPrice(): float
            {
                return 116.5;
            }
        };
    }

    public function getBasketCurrency()
    {
        return (object) ['name' => 'EUR'];
    }

    public function getProductsCount(): int
    {
        return 1;
    }
}

/**
 * The service with its three shop seams replaced: the Order core would build,
 * the session basket, and the session challenge core uses as the order id.
 */
class TestableOrderService extends OxidShopOrderService
{
    public function __construct(
        private readonly Order $order,
        private readonly ?Basket $basket = new FixtureBasket(),
    ) {
        parent::__construct(new SpyOrderRepository(), new OrderShippingAddressCopier());
    }

    protected function newOrder(): Order
    {
        return $this->order;
    }

    protected function sessionBasket(): ?Basket
    {
        return $this->basket;
    }

    protected function sessionChallenge(): ?string
    {
        return 'challenge-1';
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
 * createOrder() against the real shop is covered by the integration suite;
 * the cancellation path is pure delegation and is pinned here, and since
 * MOL-18 the creation path is pinned through the newOrder() seam too.
 */
final class OxidShopOrderServiceTest extends TestCase
{
    public function testImplementsTheSharedContract(): void
    {
        $this->assertInstanceOf(
            ShopOrderServiceInterface::class,
            new OxidShopOrderService(new SpyOrderRepository(), new OrderShippingAddressCopier())
        );
    }

    /**
     * Cancelling is the same storno + voucher release the cleanup command does,
     * written once rather than copied into each provider's adapter.
     */
    public function testCancellingStornosTheOrderThenReleasesItsVouchers(): void
    {
        $repository = new SpyOrderRepository();

        $service = new OxidShopOrderService($repository, new OrderShippingAddressCopier());
        $this->assertTrue($service->deleteNotFinishedOrder('order-1'));
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

        $service = new OxidShopOrderService($repository, new OrderShippingAddressCopier());
        $this->assertFalse($service->deleteNotFinishedOrder('order-1'));
        $this->assertSame(['cancel:order-1'], $repository->calls, 'vouchers must not be released');
    }

    /**
     * MOL-18: core answers ORDER_STATE_ORDEREXISTS when `sess_challenge` already
     * names an order row - "Order now" clicked twice. The Order object is not
     * loaded from the basket then; saving it wrote a second, empty order that
     * the shopper was sent to pay for. The signal must become a named error.
     */
    public function testCreateOrder_WhenFinalizeReportsOrderExists_ThrowsOrderExistsAndSavesNothing(): void
    {
        $order = new ScriptedOrder(Order::ORDER_STATE_ORDEREXISTS);
        $service = new TestableOrderService($order);

        try {
            $service->createOrder($this->request());
            self::fail('a second submission of one checkout attempt must be refused');
        } catch (ShopOrderException $e) {
            self::assertSame(OxidShopOrderService::ERROR_ORDER_EXISTS, $e->getErrorCode());
            self::assertSame('challenge-1', $e->getContext()['order_id']);
            self::assertSame('sess-1', $e->getContext()['session_id']);
        }

        self::assertSame(0, $order->saves, 'the never-loaded Order must not be written');
    }

    public function testCreateOrder_WhenFinalizeReportsOk_SavesOnceAndReportsTheOrder(): void
    {
        $order = new ScriptedOrder(Order::ORDER_STATE_OK);
        $service = new TestableOrderService($order);

        $response = $service->createOrder($this->request());

        self::assertSame(1, $order->saves);
        self::assertSame('order-1', $response->orderId);
        self::assertSame(42, $response->orderNumber);
        self::assertSame('completed', $response->status);
        self::assertSame('EUR', $response->currency);
    }

    /**
     * Every other non-OK state keeps its existing error code; ORDEREXISTS is
     * simply no longer among the accepted ones.
     */
    public function testCreateOrder_WhenFinalizeReportsPaymentError_ThrowsPaymentError(): void
    {
        $order = new ScriptedOrder(Order::ORDER_STATE_PAYMENTERROR);
        $service = new TestableOrderService($order);

        try {
            $service->createOrder($this->request());
            self::fail('a failed finalizeOrder() must not produce an order response');
        } catch (ShopOrderException $e) {
            self::assertSame('payment_error', $e->getErrorCode());
        }

        self::assertSame(0, $order->saves);
    }

    public function testCreateOrder_WithoutASessionBasket_ThrowsBasketNotFound(): void
    {
        $service = new TestableOrderService(new ScriptedOrder(Order::ORDER_STATE_OK), basket: null);

        $this->expectException(ShopOrderException::class);
        $this->expectExceptionMessage('Basket not found in session');

        $service->createOrder($this->request());
    }

    private function request(): CreateOrderRequest
    {
        return new CreateOrderRequest(
            sessionId: 'sess-1',
            userId: 'user-1',
            paymentId: 'oe_payments_mollie',
            initialStatus: 'NOT_FINISHED',
        );
    }
}
