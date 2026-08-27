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
 * Records the basket calls in order. Core clears the shipping and marks the
 * basket for recalculation before recording the new choice, and that sequence
 * is the reason this spy keeps a log rather than a value.
 */
final class ShippingSpyBasket
{
    /** @var list<string> */
    public array $calls = [];

    public function setShipping(?string $shipSetId = null): void
    {
        $this->calls[] = 'setShipping:' . ($shipSetId ?? 'null');
    }

    public function onUpdate(): void
    {
        $this->calls[] = 'onUpdate';
    }
}

final class ShippingSpySession
{
    /** @var array<string, mixed> */
    public array $written = [];

    /** @param array<string, mixed> $variables */
    public function __construct(
        private readonly ?ShippingSpyBasket $basket = null,
        private array $variables = [],
    ) {
    }

    public function getBasket(): ?ShippingSpyBasket
    {
        return $this->basket;
    }

    public function getVariable(string $name): mixed
    {
        return $this->variables[$name] ?? null;
    }

    public function setVariable(string $name, mixed $value): void
    {
        $this->variables[$name] = $value;
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
 * Core normally has this right already: `getPaymentList()` resolves the active
 * set during `parent::render()` and `Basket::setShipping()` mirrors it into the
 * session. So the assigner's job is to *correct*, not to write — it guarantees
 * that the value the hidden `<select>` would have submitted is the one in the
 * session, and otherwise leaves the basket alone rather than making it
 * recalculate for no change.
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
     * `validatePayment()` reads `sShipSet` from the request first and the
     * session second, and no form on the step posts it — so when the session
     * disagrees with the only available set, the session is what gets corrected.
     */
    public function testADisagreeingSessionIsCorrected(): void
    {
        $session = new ShippingSpySession(new ShippingSpyBasket(), ['sShipSet' => 'express']);
        $assigner = new TestableSingleShippingAssigner($session);

        $this->assertTrue($assigner->assign('oxidstandard'));
        $this->assertSame(['sShipSet' => 'oxidstandard'], $session->written);
    }

    public function testAnEmptySessionIsFilledIn(): void
    {
        $session = new ShippingSpySession(new ShippingSpyBasket());
        $assigner = new TestableSingleShippingAssigner($session);

        $this->assertTrue($assigner->assign('oxidstandard'));
        $this->assertSame('oxidstandard', $session->written['sShipSet'] ?? null);
    }

    /**
     * Core's `changeshipping()` clears the shipping and calls `onUpdate()` — the
     * flag that makes the basket recalculate — before recording the new choice.
     * Omitting `onUpdate()` would leave a stale delivery cost behind, and an
     * end-value assertion on the session would not notice.
     */
    public function testTheWriteFollowsCoreSequenceExactly(): void
    {
        $basket = new ShippingSpyBasket();
        $assigner = new TestableSingleShippingAssigner(
            new ShippingSpySession($basket, ['sShipSet' => 'express'])
        );

        $assigner->assign('oxidstandard');

        $this->assertSame(['setShipping:null', 'onUpdate'], $basket->calls);
    }

    /**
     * The common case, and the reason the guard exists: core already resolved
     * and persisted this very set during parent::render(). Touching the basket
     * would force a recalculation on every render of a single-carrier shop, for
     * a value that is already correct.
     */
    public function testAnAlreadyCorrectSessionIsLeftAlone(): void
    {
        $basket = new ShippingSpyBasket();
        $session = new ShippingSpySession($basket, ['sShipSet' => 'oxidstandard']);
        $assigner = new TestableSingleShippingAssigner($session);

        $this->assertTrue($assigner->assign('oxidstandard'));
        $this->assertSame([], $basket->calls);
        $this->assertSame([], $session->written);
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
