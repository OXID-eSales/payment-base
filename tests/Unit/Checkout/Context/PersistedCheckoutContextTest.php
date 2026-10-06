<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout\Context;

use LogicException;
use OxidEsales\PaymentBase\Checkout\Context\CheckoutContextInterface;
use OxidEsales\PaymentBase\Checkout\Context\HeadlessCheckoutScope;
use OxidEsales\PaymentBase\Checkout\Context\PersistedCheckoutContext;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 15 / S2 — the headless checkout's context: the flags a checkout
 * attempt used to keep in `$_SESSION`, keyed by the entered scope and kept in
 * `oe_payments_sessions` so they survive from one stateless request to the
 * next (start → return → cancel are three requests with nothing in common but
 * the ids they carry).
 */
final class PersistedCheckoutContextTest extends TestCase
{
    private HeadlessCheckoutScope $scope;

    private InMemoryCheckoutContextStore $store;

    private PersistedCheckoutContext $context;

    protected function setUp(): void
    {
        $this->scope = new HeadlessCheckoutScope();
        $this->store = new InMemoryCheckoutContextStore();
        $this->context = new PersistedCheckoutContext($this->scope, $this->store);
    }

    public function testImplementsTheContextContract(): void
    {
        self::assertInstanceOf(CheckoutContextInterface::class, $this->context);
    }

    /**
     * Reading or writing without a scope is a programming error in the entry
     * point, not a condition to paper over: there is no "current checkout" to
     * attach the flag to.
     */
    public function testWithoutAnEnteredScopeItRefusesToWork(): void
    {
        $this->expectException(LogicException::class);

        $this->context->get('anything');
    }

    public function testTheScopeIdIsTheEnteredOne(): void
    {
        $this->scope->enter('contract-1');

        self::assertSame('contract-1', $this->context->getScopeId());
    }

    public function testWritesAreStoredUnderTheScopeWithItsOwner(): void
    {
        $this->scope->enter('contract-1', userId: 'user-1', basketId: 'ub-1');

        $this->context->set('oepb_open_checkout_contract_id', 'contract-1');

        self::assertSame(['oepb_open_checkout_contract_id' => 'contract-1'], $this->store->rows['contract-1']);
        self::assertSame(['userId' => 'user-1', 'basketId' => 'ub-1'], $this->store->owners['contract-1']);
    }

    public function testReadsComeFromTheStoreOfTheEnteredScope(): void
    {
        $this->store->rows['contract-1'] = ['flag' => true];
        $this->store->rows['contract-2'] = ['flag' => false];
        $this->scope->enter('contract-2');

        self::assertFalse($this->context->get('flag'));
        self::assertSame('fallback', $this->context->get('missing', 'fallback'));
    }

    public function testRemovingDropsTheKeyAndPersists(): void
    {
        $this->store->rows['contract-1'] = ['flag' => true, 'other' => 1];
        $this->scope->enter('contract-1');

        $this->context->remove('flag');

        self::assertNull($this->context->get('flag'));
        self::assertSame(['other' => 1], $this->store->rows['contract-1']);
    }

    /**
     * Re-entering another scope in the same process must not leak the first
     * scope's cached data into the second.
     */
    public function testSwitchingScopesReloads(): void
    {
        $this->store->rows['a'] = ['flag' => 'A'];
        $this->store->rows['b'] = ['flag' => 'B'];

        $this->scope->enter('a');
        self::assertSame('A', $this->context->get('flag'));

        $this->scope->enter('b');
        self::assertSame('B', $this->context->get('flag'));
    }
}
