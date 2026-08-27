<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout;

use OxidEsales\PaymentBase\Checkout\Contract\SingleShippingAssignerInterface;
use OxidEsales\PaymentBase\Checkout\SingleShippingAssigner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Records the order of the basket writes. Core clears the shipping before
 * setting it so the lazily cached delivery cost cannot survive the change,
 * and that ordering is the reason this spy keeps a log rather than a value.
 */
final class ShippingSpyBasket
{
    /** @var list<string|null> */
    public array $shippingWrites = [];

    public function setShipping(?string $shipSetId = null): void
    {
        $this->shippingWrites[] = $shipSetId;
    }
}

final class ShippingSpySession
{
    /** @var array<string, mixed> */
    public array $written = [];

    public function __construct(private readonly ?ShippingSpyBasket $basket = null)
    {
    }

    public function getBasket(): ?ShippingSpyBasket
    {
        return $this->basket;
    }

    public function setVariable(string $name, mixed $value): void
    {
        $this->written[$name] = $value;
    }
}

/**
 * Assigner under test with its one shop seam replaced.
 */
final class TestableSingleShippingAssigner extends SingleShippingAssigner
{
    public function __construct(private readonly mixed $session)
    {
    }

    protected function getSession(): mixed
    {
        if ($this->session === 'throw') {
            throw new RuntimeException('no session');
        }

        return $this->session;
    }
}

/**
 * Sprint 07 — assigning the single delivery set.
 *
 * This is the substance of the sprint, not the cosmetics. Core never persists
 * the chosen set on a plain render: `PaymentController::getPaymentList()` calls
 * `Basket::setShipping()` but writes no session variable, only
 * `changeshipping()` does — and neither core's payment form nor sprint 06's
 * reduced form posts `sShipSet`. Once the selector is hidden the customer can
 * no longer set it even by accident, so the assigner has to.
 */
#[CoversClass(SingleShippingAssigner::class)]
final class SingleShippingAssignerTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(
            SingleShippingAssignerInterface::class,
            new TestableSingleShippingAssigner(new ShippingSpySession())
        );
    }

    /**
     * The session variable is the whole point: `validatePayment()` reads
     * `sShipSet` from the request first and the session second, and no form on
     * the step posts it.
     */
    public function testTheChosenSetIsPersistedInTheSession(): void
    {
        $session = new ShippingSpySession(new ShippingSpyBasket());
        $assigner = new TestableSingleShippingAssigner($session);

        $this->assertTrue($assigner->assign('oxidstandard'));
        $this->assertSame(['sShipSet' => 'oxidstandard'], $session->written);
    }

    /**
     * Core's `changeshipping()` clears the basket's shipping before setting it,
     * so the cached delivery cost is recomputed. Same sequence here — a single
     * `setShipping($id)` would leave a stale cost behind.
     */
    public function testBasketShippingIsClearedBeforeItIsSet(): void
    {
        $basket = new ShippingSpyBasket();
        $assigner = new TestableSingleShippingAssigner(new ShippingSpySession($basket));

        $assigner->assign('oxidstandard');

        $this->assertSame([null, 'oxidstandard'], $basket->shippingWrites);
    }

    public function testEmptySetIdIsNotAssigned(): void
    {
        $session = new ShippingSpySession(new ShippingSpyBasket());
        $assigner = new TestableSingleShippingAssigner($session);

        $this->assertFalse($assigner->assign(''));
        $this->assertSame([], $session->written);
    }

    /**
     * No basket means no checkout to assign into. Writing `sShipSet` anyway
     * would leave a delivery set pointing at nothing.
     */
    public function testMissingBasketIsNotAssigned(): void
    {
        $session = new ShippingSpySession();
        $assigner = new TestableSingleShippingAssigner($session);

        $this->assertFalse($assigner->assign('oxidstandard'));
        $this->assertSame([], $session->written);
    }

    public function testMissingSessionIsNotAssigned(): void
    {
        $assigner = new TestableSingleShippingAssigner(null);

        $this->assertFalse($assigner->assign('oxidstandard'));
    }

    /**
     * An optional convenience must never take checkout down: anything the shop
     * throws at us means "no shortcut", not "no checkout".
     */
    public function testShopFailureDegradesToNoAssignment(): void
    {
        $assigner = new TestableSingleShippingAssigner('throw');

        $this->assertFalse($assigner->assign('oxidstandard'));
    }
}
