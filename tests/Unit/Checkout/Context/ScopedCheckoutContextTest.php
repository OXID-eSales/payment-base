<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout\Context;

use OxidEsales\PaymentBase\Checkout\Context\CheckoutContextInterface;
use OxidEsales\PaymentBase\Checkout\Context\HeadlessCheckoutScope;
use OxidEsales\PaymentBase\Checkout\Context\PersistedCheckoutContext;
use OxidEsales\PaymentBase\Checkout\Context\ScopedCheckoutContext;
use OxidEsales\PaymentBase\Checkout\Context\SessionCheckoutContext;
use OxidEsales\PaymentBase\Tests\Unit\Checkout\RecordingSessionAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 15 / S2 — the one context the checkout classes are wired to. It is
 * the session's context unless a headless scope was entered for this request,
 * in which case it is the persisted one. Consumers never know which.
 */
final class ScopedCheckoutContextTest extends TestCase
{
    private HeadlessCheckoutScope $scope;

    private RecordingSessionAdapter $session;

    private InMemoryCheckoutContextStore $store;

    private ScopedCheckoutContext $context;

    protected function setUp(): void
    {
        $this->scope = new HeadlessCheckoutScope();
        $this->session = new RecordingSessionAdapter();
        $this->store = new InMemoryCheckoutContextStore();
        $this->context = new ScopedCheckoutContext(
            $this->scope,
            new SessionCheckoutContext($this->session),
            new PersistedCheckoutContext($this->scope, $this->store)
        );
    }

    public function testImplementsTheContextContract(): void
    {
        self::assertInstanceOf(CheckoutContextInterface::class, $this->context);
    }

    public function testWithoutAHeadlessScopeItIsTheSession(): void
    {
        $this->context->set('flag', 'twig');

        self::assertSame('twig', $this->session->getVariable('flag'));
        self::assertSame([], $this->store->rows, 'a Twig checkout must never write the headless store');
        self::assertSame('session-1', $this->context->getScopeId());
    }

    public function testWithAHeadlessScopeItIsThePersistedContext(): void
    {
        $this->scope->enter('contract-1', userId: 'user-1');

        $this->context->set('flag', 'headless');

        self::assertSame(['flag' => 'headless'], $this->store->rows['contract-1']);
        self::assertNull($this->session->getVariable('flag'), 'a headless checkout must never touch the PHP session');
        self::assertSame('contract-1', $this->context->getScopeId());
    }

    public function testLeavingTheScopeGoesBackToTheSession(): void
    {
        $this->scope->enter('contract-1');
        $this->context->set('flag', 'headless');
        $this->scope->leave();

        self::assertNull($this->context->get('flag'));
        $this->context->remove('flag');
        self::assertSame(['flag' => 'headless'], $this->store->rows['contract-1'], 'the headless row is untouched after leaving');
    }
}
