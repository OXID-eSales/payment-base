<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Context;

use InvalidArgumentException;

/**
 * Which headless checkout this request is about.
 *
 * A headless request has no session to be "in". Its entry point - a GraphQL
 * mutation, an MCP tool - enters a scope here (the contract id; the basket id
 * for a start, before a contract exists) and everything downstream that used
 * to read the session reads that scope's {@see PersistedCheckoutContext}.
 * One PHP process serves one request, so a mutable service is the right
 * shape; a Twig request never enters one and the scope stays inactive.
 *
 * @since 3.0.0
 */
final class HeadlessCheckoutScope implements HeadlessCheckoutScopeInterface
{
    private ?string $scopeId = null;

    private ?string $userId = null;

    private ?string $basketId = null;

    public function enter(string $scopeId, ?string $userId = null, ?string $basketId = null): void
    {
        if ($scopeId === '') {
            throw new InvalidArgumentException('A headless checkout scope needs a non-empty id');
        }

        $this->scopeId = $scopeId;
        $this->userId = $userId;
        $this->basketId = $basketId;
    }

    public function leave(): void
    {
        $this->scopeId = null;
        $this->userId = null;
        $this->basketId = null;
    }

    public function isActive(): bool
    {
        return $this->scopeId !== null;
    }

    public function getScopeId(): ?string
    {
        return $this->scopeId;
    }

    public function getUserId(): ?string
    {
        return $this->userId;
    }

    public function getBasketId(): ?string
    {
        return $this->basketId;
    }
}
