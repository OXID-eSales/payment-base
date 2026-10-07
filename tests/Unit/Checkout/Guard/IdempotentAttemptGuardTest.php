<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout\Guard;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Adapter\Exception\ShopOrderException;
use OxidEsales\PaymentBase\Adapter\OxidShopOrderService;
use OxidEsales\PaymentBase\Adapter\Request\CreateOrderRequest;
use OxidEsales\PaymentBase\Checkout\Guard\CheckoutAttemptGuardInterface;
use OxidEsales\PaymentBase\Checkout\Guard\IdempotentAttemptGuard;
use PHPUnit\Framework\TestCase;

/**
 * The guard with a clock that tests can move.
 */
final class ClockedAttemptGuard extends IdempotentAttemptGuard
{
    public DateTimeImmutable $clock;

    public function __construct(InMemoryIdempotencyRepository $records)
    {
        parent::__construct($records);
        $this->clock = new DateTimeImmutable('2026-10-06 12:00:00');
    }

    protected function now(): DateTimeImmutable
    {
        return $this->clock;
    }
}

/**
 * Sprint 15 / S3 — "Order now" clicked twice, headless edition. In the Twig
 * checkout core itself refuses the second submission: `sess_challenge` is the
 * order id and finalizeOrder() answers ORDEREXISTS. A headless request has no
 * session, so core would mint a fresh id every time and two concurrent
 * CheckoutStart calls for one basket would create two orders. This guard
 * gives the headless path the same one-order-per-attempt rule, keyed by
 * (user, basket) in `oe_payments_idempotency`.
 */
final class IdempotentAttemptGuardTest extends TestCase
{
    private InMemoryIdempotencyRepository $records;

    private ClockedAttemptGuard $guard;

    protected function setUp(): void
    {
        $this->records = new InMemoryIdempotencyRepository();
        $this->guard = new ClockedAttemptGuard($this->records);
    }

    public function testImplementsTheGuardContract(): void
    {
        self::assertInstanceOf(CheckoutAttemptGuardInterface::class, $this->guard);
    }

    public function testTheErrorCodeIsTheOneTheOrderServiceAlreadyUses(): void
    {
        self::assertSame(OxidShopOrderService::ERROR_ORDER_EXISTS, CheckoutAttemptGuardInterface::ERROR_ORDER_EXISTS);
    }

    /**
     * The Twig checkout is guarded by core (`sess_challenge`); this guard must
     * not add a second, differently-keyed rule on top of it.
     */
    public function testASessionRequestIsNotRecorded(): void
    {
        $request = $this->request(basketId: null);

        $this->guard->claim($request);
        $this->guard->complete($request, 'order-1');
        $this->guard->release($request);

        self::assertSame(0, $this->records->saves);
    }

    public function testTheFirstClaimRecordsAnInFlightAttemptForUserAndBasket(): void
    {
        $this->guard->claim($this->request(basketId: 'ub-1'));

        $record = $this->records->findByKey('order_create:user-1:ub-1');
        self::assertNotNull($record);
        self::assertSame(IdempotentAttemptGuard::STATUS_IN_FLIGHT, $record->getStatus());
        self::assertSame(IdempotentAttemptGuard::OPERATION, $record->getOperation());
        self::assertSame('', $record->getOrderId(), 'no order yet');
        self::assertEquals(new DateTimeImmutable('2026-10-06 12:02:00'), $record->getExpiresAt(), 'two-minute window');
    }

    public function testASecondClaimInsideTheWindowIsRefusedAsOrderExists(): void
    {
        $this->guard->claim($this->request(basketId: 'ub-1'));

        try {
            $this->guard->claim($this->request(basketId: 'ub-1'));
            self::fail('the same attempt must not be started twice');
        } catch (ShopOrderException $e) {
            self::assertSame(CheckoutAttemptGuardInterface::ERROR_ORDER_EXISTS, $e->getErrorCode());
            self::assertSame('ub-1', $e->getContext()['basket_id']);
            self::assertSame('user-1', $e->getContext()['user_id']);
            self::assertNull($e->getContext()['order_id'], 'still in flight, no order id to name yet');
        }
    }

    public function testCompletingNamesTheOrderAndStillRefusesARepeat(): void
    {
        $request = $this->request(basketId: 'ub-1');
        $this->guard->claim($request);

        $this->guard->complete($request, 'order-1');

        $record = $this->records->findByKey('order_create:user-1:ub-1');
        self::assertSame(IdempotentAttemptGuard::STATUS_COMPLETED, $record?->getStatus());
        self::assertSame('order-1', $record?->getOrderId());

        try {
            $this->guard->claim($request);
            self::fail('a repeat right after completion is the double click core refuses too');
        } catch (ShopOrderException $e) {
            self::assertSame('order-1', $e->getContext()['order_id']);
        }
    }

    /**
     * Order creation failed (article gone, delivery invalid): the shopper must
     * be able to try again at once, not wait out the window.
     */
    public function testReleasingLetsTheNextClaimThrough(): void
    {
        $request = $this->request(basketId: 'ub-1');
        $this->guard->claim($request);

        $this->guard->release($request);
        $this->guard->claim($request);

        self::assertSame(
            IdempotentAttemptGuard::STATUS_IN_FLIGHT,
            $this->records->findByKey('order_create:user-1:ub-1')?->getStatus()
        );
    }

    public function testAfterTheWindowANewAttemptIsAllowed(): void
    {
        $request = $this->request(basketId: 'ub-1');
        $this->guard->claim($request);
        $this->guard->complete($request, 'order-1');

        $this->guard->clock = new DateTimeImmutable('2026-10-06 12:02:01');
        $this->guard->claim($request);

        $record = $this->records->findByKey('order_create:user-1:ub-1');
        self::assertSame(IdempotentAttemptGuard::STATUS_IN_FLIGHT, $record?->getStatus());
        self::assertSame('', $record?->getOrderId());
    }

    public function testDifferentBasketsOrUsersDoNotBlockEachOther(): void
    {
        $this->guard->claim($this->request(basketId: 'ub-1'));
        $this->guard->claim($this->request(basketId: 'ub-2'));
        $this->guard->claim($this->request(basketId: 'ub-1', userId: 'user-2'));

        self::assertCount(3, $this->records->records);
    }

    private function request(?string $basketId, string $userId = 'user-1'): CreateOrderRequest
    {
        return new CreateOrderRequest(
            sessionId: 'headless',
            userId: $userId,
            paymentId: 'oe_payments_stripe_wallet',
            basketId: $basketId,
        );
    }
}
