<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Context;

/**
 * The one context the checkout classes are wired to: the session's unless a
 * headless scope was entered for this request, then the persisted one.
 *
 * @since 3.0.0
 */
final class ScopedCheckoutContext implements CheckoutContextInterface
{
    public function __construct(
        private readonly HeadlessCheckoutScopeInterface $scope,
        private readonly CheckoutContextInterface $session,
        private readonly CheckoutContextInterface $persisted
    ) {
    }

    public function getScopeId(): string
    {
        return $this->active()->getScopeId();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->active()->get($key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        $this->active()->set($key, $value);
    }

    public function remove(string $key): void
    {
        $this->active()->remove($key);
    }

    private function active(): CheckoutContextInterface
    {
        return $this->scope->isActive() ? $this->persisted : $this->session;
    }
}
