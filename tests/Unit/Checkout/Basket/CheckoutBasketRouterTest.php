<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout\Basket;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\PaymentBase\Adapter\Request\CreateOrderRequest;
use OxidEsales\PaymentBase\Checkout\Basket\CheckoutBasketProviderInterface;
use OxidEsales\PaymentBase\Checkout\Basket\CheckoutBasketRouter;
use PHPUnit\Framework\TestCase;

/**
 * A provider that answers a fixed basket and remembers whether it was asked.
 */
final class StubBasketProvider implements CheckoutBasketProviderInterface
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

final class MarkerBasket extends Basket
{
    public function __construct(public readonly string $marker)
    {
    }
}

/**
 * Sprint 15 / S1 — one order service, two basket sources. A request that
 * names a basket id is a headless checkout (GraphQL Storefront, MCP) and must
 * be served from the persisted user basket; a request without one is the Twig
 * / OPC checkout and keeps reading the session basket, byte-identically.
 */
final class CheckoutBasketRouterTest extends TestCase
{
    public function testImplementsTheProviderContract(): void
    {
        $router = new CheckoutBasketRouter(new StubBasketProvider(null), new StubBasketProvider(null));

        self::assertInstanceOf(CheckoutBasketProviderInterface::class, $router);
    }

    public function testWithoutABasketIdTheSessionProviderAnswers(): void
    {
        $session = new StubBasketProvider(new MarkerBasket('session'));
        $userBasket = new StubBasketProvider(new MarkerBasket('user'));
        $router = new CheckoutBasketRouter($session, $userBasket);

        $basket = $router->basketFor($this->request(basketId: null));

        self::assertInstanceOf(MarkerBasket::class, $basket);
        self::assertSame('session', $basket->marker);
        self::assertNull($userBasket->askedWith, 'the user-basket provider must not touch the database for a Twig checkout');
    }

    public function testWithABasketIdTheUserBasketProviderAnswers(): void
    {
        $session = new StubBasketProvider(new MarkerBasket('session'));
        $userBasket = new StubBasketProvider(new MarkerBasket('user'));
        $router = new CheckoutBasketRouter($session, $userBasket);

        $basket = $router->basketFor($this->request(basketId: 'ub-1'));

        self::assertInstanceOf(MarkerBasket::class, $basket);
        self::assertSame('user', $basket->marker);
        self::assertNull($session->askedWith, 'a headless checkout must never fall back to whatever is in the session');
        self::assertSame('ub-1', $userBasket->askedWith?->basketId);
    }

    /**
     * An unknown basket id is "not found", not "use the session instead": the
     * caller named a basket, and silently serving another one would create an
     * order for the wrong basket.
     */
    public function testAnUnknownBasketIdIsNotFoundAndDoesNotFallBackToTheSession(): void
    {
        $session = new StubBasketProvider(new MarkerBasket('session'));
        $router = new CheckoutBasketRouter($session, new StubBasketProvider(null));

        self::assertNull($router->basketFor($this->request(basketId: 'missing')));
        self::assertNull($session->askedWith);
    }

    private function request(?string $basketId): CreateOrderRequest
    {
        return new CreateOrderRequest(
            sessionId: 'sess-1',
            userId: 'user-1',
            paymentId: 'oe_payments_stripe_wallet',
            basketId: $basketId,
        );
    }
}
