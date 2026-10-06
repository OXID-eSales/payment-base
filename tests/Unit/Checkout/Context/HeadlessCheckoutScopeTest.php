<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout\Context;

use OxidEsales\PaymentBase\Checkout\Context\HeadlessCheckoutScope;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 15 / S2 — a headless request has no session to be "in". The entry
 * point (GraphQL mutation, MCP tool) enters a scope - the contract id, or the
 * basket id before a contract exists - and everything that used to read the
 * session reads that scope's context instead. One PHP process is one request,
 * so the scope is a plain mutable service.
 */
final class HeadlessCheckoutScopeTest extends TestCase
{
    public function testNoScopeIsActiveUntilOneIsEntered(): void
    {
        $scope = new HeadlessCheckoutScope();

        self::assertFalse($scope->isActive());
        self::assertNull($scope->getScopeId());
        self::assertNull($scope->getUserId());
        self::assertNull($scope->getBasketId());
    }

    public function testEnteringMakesTheScopeActiveWithItsOwner(): void
    {
        $scope = new HeadlessCheckoutScope();

        $scope->enter('contract-1', userId: 'user-1', basketId: 'ub-1');

        self::assertTrue($scope->isActive());
        self::assertSame('contract-1', $scope->getScopeId());
        self::assertSame('user-1', $scope->getUserId());
        self::assertSame('ub-1', $scope->getBasketId());
    }

    public function testEnteringAgainReplacesTheScope(): void
    {
        $scope = new HeadlessCheckoutScope();
        $scope->enter('basket-scope', userId: 'user-1', basketId: 'ub-1');

        $scope->enter('contract-1', userId: 'user-1', basketId: 'ub-1');

        self::assertSame('contract-1', $scope->getScopeId());
    }

    public function testLeavingDeactivatesIt(): void
    {
        $scope = new HeadlessCheckoutScope();
        $scope->enter('contract-1');

        $scope->leave();

        self::assertFalse($scope->isActive());
        self::assertNull($scope->getScopeId());
    }

    public function testAnEmptyScopeIdIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new HeadlessCheckoutScope())->enter('');
    }
}
