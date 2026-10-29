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
use OxidEsales\PaymentBase\Adapter\OrderRestockInterface;
use OxidEsales\PaymentBase\Adapter\OrderShippingAddressCopier;
use OxidEsales\PaymentBase\Adapter\OxidShopOrderService;
use OxidEsales\PaymentBase\Adapter\Request\CreateOrderRequest;
use OxidEsales\PaymentBase\Adapter\ShopOrderServiceInterface;
use OxidEsales\PaymentBase\Checkout\Basket\CheckoutBasketProviderInterface;
use OxidEsales\PaymentBase\Checkout\Guard\CheckoutAttemptGuardInterface;
use OxidEsales\PaymentBase\Adapter\ShopMailSenderInterface;
use OxidEsales\PaymentBase\Mail\ClaimedOrderMails;
use OxidEsales\PaymentBase\Mail\DeferredOrderMail;
use OxidEsales\PaymentBase\Mail\DefersConfirmationMailInterface;
use OxidEsales\PaymentBase\Repository\ContractOrderMailStoreInterface;
use OxidEsales\PaymentBase\Repository\NotFinishedOrderRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * Records what the canonical adapter asked the repository to do.
 */
/** OPC-175 / S4: also the restock, so one call list shows the order of the steps. */
class SpyOrderRepository implements NotFinishedOrderRepositoryInterface, OrderRestockInterface
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

    public function restock(string $orderId): void
    {
        $this->calls[] = "restock:$orderId";
    }
}

/**
 * An Order whose finalizeOrder() answers a chosen core state, and which counts
 * how often the service saved it (MOL-18).
 */
class ScriptedOrder extends Order
{
    public int $saves = 0;

    /** The active user set via setUser() before finalizeOrder() ran (PB-7.6). */
    public ?object $activeUserBeforeFinalize = null;

    private bool $finalized = false;

    public function __construct(private readonly int $finalizeState)
    {
    }

    public function setUser($user)
    {
        if (!$this->finalized) {
            $this->activeUserBeforeFinalize = $user;
        }
        parent::setUser($user);
    }

    public function finalizeOrder($basket, $user, $recalculating = false): int
    {
        $this->finalized = true;

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
            'oxshopid' => 1,
            default => '',
        };
    }
}

/**
 * STRP-176 — what the payment-base Order extension is in the shop: an order
 * that can hold its confirmation mail back. Records when it was asked to.
 */
class DeferringScriptedOrder extends ScriptedOrder implements DefersConfirmationMailInterface
{
    /** @var list<string> */
    public array $events = [];

    /** @var list<DeferredOrderMail> what finalizeOrder() "rendered" */
    public array $rendered = [];

    public function deferConfirmationMail(): void
    {
        $this->events[] = 'defer';
    }

    public function takeDeferredMails(): array
    {
        $this->events[] = 'take';
        $mails = $this->rendered;
        $this->rendered = [];

        return $mails;
    }

    public function finalizeOrder($basket, $user, $recalculating = false): int
    {
        $this->events[] = 'finalize';

        return parent::finalizeOrder($basket, $user, $recalculating);
    }
}

/**
 * STRP-176 — the contract row the mails wait on.
 */
class SpyContractOrderMails implements ContractOrderMailStoreInterface
{
    /** @var list<array{0: string, 1: list<DeferredOrderMail>}> */
    public array $stashed = [];

    public bool $rowExists = true;

    public ?\RuntimeException $failWith = null;

    public function stash(string $contractId, array $mails): bool
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
        $this->stashed[] = [$contractId, $mails];

        return $this->rowExists;
    }

    public function claim(string $contractId): ?ClaimedOrderMails
    {
        return null;
    }

    public function markSent(string $contractId): void
    {
    }

    public function markFailed(string $contractId, array $unsent): void
    {
    }

    public function dropByOrderId(string $orderId): int
    {
        return 0;
    }
}

class SpyMailSender implements ShopMailSenderInterface
{
    /** @var list<array{0: string, 1: int}> kind, shop */
    public array $sent = [];

    public function send(DeferredOrderMail $mail, int $shopId): bool
    {
        $this->sent[] = [$mail->kind, $shopId];

        return true;
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
 * A basket provider that answers a fixed basket and remembers the request it
 * was asked with (Sprint 15 / S1: the service no longer reads the session
 * itself; it asks the provider).
 */
class StaticBasketProvider implements CheckoutBasketProviderInterface
{
    public ?CreateOrderRequest $askedWith = null;

    public function __construct(private readonly ?Basket $basket)
    {
    }

    public function basketFor(CreateOrderRequest $request): ?Basket
    {
        $this->askedWith = $request;

        return $this->basket;
    }
}

/**
 * Records the order in which the service talks to the attempt guard
 * (Sprint 15 / S3), and can refuse the claim like a guard that found the
 * same attempt already in flight.
 */
class SpyAttemptGuard implements CheckoutAttemptGuardInterface
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(private readonly bool $refuses = false)
    {
    }

    public function claim(CreateOrderRequest $request): void
    {
        $this->calls[] = 'claim:' . ($request->basketId ?? '-');
        if ($this->refuses) {
            throw new ShopOrderException(
                'An order for this checkout attempt already exists',
                self::ERROR_ORDER_EXISTS,
                ['order_id' => 'order-0', 'basket_id' => $request->basketId]
            );
        }
    }

    public function complete(CreateOrderRequest $request, string $orderId): void
    {
        $this->calls[] = 'complete:' . $orderId;
    }

    public function release(CreateOrderRequest $request): void
    {
        $this->calls[] = 'release:' . ($request->basketId ?? '-');
    }
}

/**
 * The service with its shop seams replaced: the Order core would build, the
 * basket provider, the attempt guard, and the session challenge core uses as
 * the order id.
 */
class TestableOrderService extends OxidShopOrderService
{
    public readonly StaticBasketProvider $basketProvider;

    public function __construct(
        private readonly Order $order,
        ?Basket $basket = new FixtureBasket(),
        ?StaticBasketProvider $basketProvider = null,
        ?CheckoutAttemptGuardInterface $attemptGuard = null,
        ?ContractOrderMailStoreInterface $orderMails = null,
        ?ShopMailSenderInterface $mailSender = null,
    ) {
        $this->basketProvider = $basketProvider ?? new StaticBasketProvider($basket);
        $repository = new SpyOrderRepository();
        parent::__construct(
            $repository,
            new OrderShippingAddressCopier(),
            $repository,
            $this->basketProvider,
            $attemptGuard,
            $orderMails,
            $mailSender
        );
    }

    protected function newOrder(): Order
    {
        return $this->order;
    }

    protected function sessionChallenge(): ?string
    {
        return 'challenge-1';
    }

    /**
     * The real seam re-loads the user via oxNew() for the full row (EE's
     * Order::setUser() clones an EE-only field); units stay shop-free.
     */
    protected function activeUserForFinalize(User $user): User
    {
        return $user;
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
            new OxidShopOrderService(new SpyOrderRepository(), new OrderShippingAddressCopier(), new SpyOrderRepository())
        );
    }

    /**
     * Cancelling is the same storno + voucher release + restock (OPC-175 / S4)
     * the cleanup command does, written once rather than copied into each
     * provider's adapter.
     */
    public function testCancellingStornosTheOrderThenReleasesItsVouchersAndStock(): void
    {
        $repository = new SpyOrderRepository();

        $service = new OxidShopOrderService($repository, new OrderShippingAddressCopier(), $repository);
        $this->assertTrue($service->deleteNotFinishedOrder('order-1'));
        $this->assertSame(['cancel:order-1', 'vouchers:order-1', 'restock:order-1'], $repository->calls);
    }

    /**
     * The write is guarded on the order still being NOT_FINISHED. When it no
     * longer is, the vouchers belong to a live order and must not be handed
     * back — so the guard's answer decides, not the caller.
     */
    public function testAnOrderThatNoLongerQualifiesKeepsItsVouchers(): void
    {
        $repository = new SpyOrderRepository(cancelSucceeds: false);

        $service = new OxidShopOrderService($repository, new OrderShippingAddressCopier(), $repository);
        $this->assertFalse($service->deleteNotFinishedOrder('order-1'));
        $this->assertSame(['cancel:order-1'], $repository->calls, 'vouchers and stock must not be released');
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

    public function testCreateOrder_SetsTheCheckoutUserAsActiveUserBeforeFinalize(): void
    {
        // OXID 7.6: Order::getDelAddressInfo() only resolves a selected
        // delivery address (`deladrid`) when the ORDER's active user owns it —
        // and Base::getUser() falls back to loadActiveUser(), which is empty
        // in a headless checkout. Without setUser() before finalizeOrder(),
        // validateDeliveryAddress() drops the address from the expected hash
        // and every deladrid checkout dies with invalid_delivery_address.
        $order = new ScriptedOrder(Order::ORDER_STATE_OK);
        $service = new TestableOrderService($order);

        $service->createOrder($this->request());

        self::assertInstanceOf(User::class, $order->activeUserBeforeFinalize);
        self::assertSame('user-1', $order->activeUserBeforeFinalize->getId());
    }

    /**
     * STRP-176 — the order is created before the customer pays at the PSP;
     * finalizeOrder() must not mail "thank you for your order" yet.
     */
    public function testCreateOrder_DefersTheConfirmationMailBeforeFinalize(): void
    {
        $order = new DeferringScriptedOrder(Order::ORDER_STATE_OK);

        (new TestableOrderService($order))->createOrder($this->request());

        self::assertSame(['defer', 'finalize', 'take'], $order->events);
    }

    public function testCreateOrder_KeepsTheRenderedMailsOnTheContractTheRequestNames(): void
    {
        $order = $this->orderThatRendered();
        $mails = new SpyContractOrderMails();
        $sender = new SpyMailSender();

        (new TestableOrderService($order, orderMails: $mails, mailSender: $sender))
            ->createOrder($this->requestFor('contract-1'));

        self::assertSame(['defer', 'finalize', 'take'], $order->events);
        self::assertCount(1, $mails->stashed);
        self::assertSame('contract-1', $mails->stashed[0][0]);
        self::assertSame(['customer', 'owner'], array_map(
            static fn (DeferredOrderMail $mail): string => $mail->kind,
            $mails->stashed[0][1]
        ));
        self::assertSame([], $sender->sent, 'nothing goes out before the payment');
    }

    public function testCreateOrder_WithoutAContractId_SendsTheMailsNow(): void
    {
        $mails = new SpyContractOrderMails();
        $sender = new SpyMailSender();

        (new TestableOrderService($this->orderThatRendered(), orderMails: $mails, mailSender: $sender))
            ->createOrder($this->request());

        self::assertSame([], $mails->stashed);
        self::assertSame([['customer', 1], ['owner', 1]], $sender->sent);
    }

    public function testCreateOrder_WhenTheContractRowIsMissing_SendsTheMailsNow(): void
    {
        $mails = new SpyContractOrderMails();
        $mails->rowExists = false;
        $sender = new SpyMailSender();

        (new TestableOrderService($this->orderThatRendered(), orderMails: $mails, mailSender: $sender))
            ->createOrder($this->requestFor('contract-1'));

        self::assertSame([['customer', 1], ['owner', 1]], $sender->sent);
    }

    public function testCreateOrder_WhenKeepingTheMailsFails_SendsThemNowAndTheCheckoutGoesOn(): void
    {
        $mails = new SpyContractOrderMails();
        $mails->failWith = new \RuntimeException('db down');
        $sender = new SpyMailSender();

        $response = (new TestableOrderService($this->orderThatRendered(), orderMails: $mails, mailSender: $sender))
            ->createOrder($this->requestFor('contract-1'));

        self::assertSame('completed', $response->status);
        self::assertSame([['customer', 1], ['owner', 1]], $sender->sent);
    }

    public function testCreateOrder_WithoutAStore_SendsTheMailsNow(): void
    {
        // A services.yaml override from before 1.6.0 builds the service
        // without the store: the mail goes out as before STRP-176.
        $sender = new SpyMailSender();

        (new TestableOrderService($this->orderThatRendered(), mailSender: $sender))
            ->createOrder($this->requestFor('contract-1'));

        self::assertSame([['customer', 1], ['owner', 1]], $sender->sent);
    }

    public function testCreateOrder_WithADeferredMail_StillReportsTheOrderCompleted(): void
    {
        $order = new DeferringScriptedOrder(Order::ORDER_STATE_OK);

        $response = (new TestableOrderService($order))->createOrder($this->request());

        self::assertSame('completed', $response->status);
        self::assertSame(1, $order->saves);
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

    public function testCreateOrder_WhenTheProviderHasNoBasket_ThrowsBasketNotFound(): void
    {
        $service = new TestableOrderService(new ScriptedOrder(Order::ORDER_STATE_OK), basket: null);

        try {
            $service->createOrder($this->request());
            self::fail('no basket, no order');
        } catch (ShopOrderException $e) {
            self::assertSame('basket_not_found', $e->getErrorCode());
            self::assertSame('sess-1', $e->getContext()['session_id']);
        }
    }

    /**
     * Sprint 15 / S1: the service does not know where baskets live any more.
     * It hands the whole request to the provider, so a headless request
     * (basket id set) and a Twig request (none) are told apart there, not here.
     */
    public function testCreateOrder_AsksTheBasketProviderWithTheRequest(): void
    {
        $provider = new StaticBasketProvider(new FixtureBasket());
        $service = new TestableOrderService(new ScriptedOrder(Order::ORDER_STATE_OK), basketProvider: $provider);

        $service->createOrder($this->request(basketId: 'ub-1'));

        self::assertSame('ub-1', $provider->askedWith?->basketId);
        self::assertSame('user-1', $provider->askedWith?->userId);
    }

    /**
     * Sprint 15 / S3: the headless double click. The guard is asked FIRST -
     * before the basket is even loaded - and told the order id when the order
     * exists, so the next claim can name it.
     */
    public function testCreateOrder_ClaimsTheAttemptBeforeAnythingAndCompletesItWithTheOrder(): void
    {
        $guard = new SpyAttemptGuard();
        $service = new TestableOrderService(new ScriptedOrder(Order::ORDER_STATE_OK), attemptGuard: $guard);

        $service->createOrder($this->request(basketId: 'ub-1'));

        self::assertSame(['claim:ub-1', 'complete:order-1'], $guard->calls);
    }

    public function testCreateOrder_WhenTheGuardRefuses_CreatesNothing(): void
    {
        $guard = new SpyAttemptGuard(refuses: true);
        $order = new ScriptedOrder(Order::ORDER_STATE_OK);
        $provider = new StaticBasketProvider(new FixtureBasket());
        $service = new TestableOrderService($order, basketProvider: $provider, attemptGuard: $guard);

        try {
            $service->createOrder($this->request(basketId: 'ub-1'));
            self::fail('a refused claim must not create an order');
        } catch (ShopOrderException $e) {
            self::assertSame(OxidShopOrderService::ERROR_ORDER_EXISTS, $e->getErrorCode());
            self::assertSame('order-0', $e->getContext()['order_id']);
        }

        self::assertSame(0, $order->saves);
        self::assertNull($provider->askedWith, 'refused before the basket is loaded');
        self::assertSame(['claim:ub-1'], $guard->calls, 'nothing to complete or release: the claim never happened');
    }

    /**
     * Order creation failed after the claim: the claim is given back so the
     * shopper can retry at once instead of waiting out the window.
     */
    public function testCreateOrder_WhenFinalizeFails_ReleasesTheClaim(): void
    {
        $guard = new SpyAttemptGuard();
        $service = new TestableOrderService(new ScriptedOrder(Order::ORDER_STATE_PAYMENTERROR), attemptGuard: $guard);

        try {
            $service->createOrder($this->request(basketId: 'ub-1'));
            self::fail('payment error must surface');
        } catch (ShopOrderException) {
        }

        self::assertSame(['claim:ub-1', 'release:ub-1'], $guard->calls);
    }

    public function testCreateOrder_WhenNoBasket_ReleasesTheClaim(): void
    {
        $guard = new SpyAttemptGuard();
        $service = new TestableOrderService(new ScriptedOrder(Order::ORDER_STATE_OK), basket: null, attemptGuard: $guard);

        try {
            $service->createOrder($this->request(basketId: 'ub-1'));
            self::fail('no basket, no order');
        } catch (ShopOrderException) {
        }

        self::assertSame(['claim:ub-1', 'release:ub-1'], $guard->calls);
    }

    public function testCreateOrder_WithoutAGuard_BehavesAsBefore(): void
    {
        $service = new TestableOrderService(new ScriptedOrder(Order::ORDER_STATE_OK));

        $response = $service->createOrder($this->request());

        self::assertSame('order-1', $response->orderId);
    }

    private function requestFor(string $contractId): CreateOrderRequest
    {
        return new CreateOrderRequest(
            sessionId: 'sess-1',
            userId: 'user-1',
            paymentId: 'oe_payments_mollie',
            metadata: ['contract_id' => $contractId],
            initialStatus: 'NOT_FINISHED',
        );
    }

    private function orderThatRendered(): DeferringScriptedOrder
    {
        $order = new DeferringScriptedOrder(Order::ORDER_STATE_OK);
        foreach ([DeferredOrderMail::KIND_CUSTOMER, DeferredOrderMail::KIND_OWNER] as $kind) {
            $order->rendered[] = new DeferredOrderMail($kind, 'order@shop.test', 'Shop', [['jane@example.test', 'Jane']], [], [], [], 'Your order (#42)', '<p>Thanks</p>', 'Thanks');
        }

        return $order;
    }

    private function request(?string $basketId = null): CreateOrderRequest
    {
        return new CreateOrderRequest(
            sessionId: 'sess-1',
            userId: 'user-1',
            paymentId: 'oe_payments_mollie',
            initialStatus: 'NOT_FINISHED',
            basketId: $basketId,
        );
    }
}
