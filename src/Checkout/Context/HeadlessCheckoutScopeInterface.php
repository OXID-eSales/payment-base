<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Context;

/**
 * Which headless checkout this request is about. Entered by the headless
 * entry point (GraphQL mutation, MCP tool), read by {@see ScopedCheckoutContext}
 * and {@see PersistedCheckoutContext}. Inactive for every Twig request.
 *
 * @since 3.0.0
 */
interface HeadlessCheckoutScopeInterface
{
    /**
     * @param string $scopeId the contract id, or the basket id before a contract exists
     * @throws \InvalidArgumentException on an empty id
     */
    public function enter(string $scopeId, ?string $userId = null, ?string $basketId = null): void;

    public function leave(): void;

    public function isActive(): bool;

    public function getScopeId(): ?string;

    public function getUserId(): ?string;

    public function getBasketId(): ?string;
}
