<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Context;

use LogicException;

/**
 * The headless checkout's context: the flags of the entered scope, kept in
 * `oe_payments_sessions` so they survive from one stateless request to the
 * next (start → return → cancel share nothing but the ids they carry).
 *
 * Reading or writing without an entered scope is a programming error in the
 * entry point and is refused, not papered over: there is no "current
 * checkout" the flag could belong to.
 *
 * @since 3.0.0
 */
final class PersistedCheckoutContext implements CheckoutContextInterface
{
    /** @var array<string, mixed>|null */
    private ?array $data = null;

    private ?string $loadedScopeId = null;

    public function __construct(
        private readonly HeadlessCheckoutScopeInterface $scope,
        private readonly CheckoutContextStoreInterface $store
    ) {
    }

    public function getScopeId(): string
    {
        $scopeId = $this->scope->getScopeId();
        if ($scopeId === null) {
            throw new LogicException('No headless checkout scope entered; the persisted context has nothing to key by');
        }

        return $scopeId;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data()[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $data = $this->data();
        $data[$key] = $value;
        $this->persist($data);
    }

    public function remove(string $key): void
    {
        $data = $this->data();
        unset($data[$key]);
        $this->persist($data);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(): array
    {
        $scopeId = $this->getScopeId();
        if ($this->data === null || $this->loadedScopeId !== $scopeId) {
            $this->data = $this->store->load($scopeId);
            $this->loadedScopeId = $scopeId;
        }

        return $this->data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function persist(array $data): void
    {
        $this->data = $data;
        $this->store->save($this->getScopeId(), $data, $this->scope->getUserId(), $this->scope->getBasketId());
    }
}
