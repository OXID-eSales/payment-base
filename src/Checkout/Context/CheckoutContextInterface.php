<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Context;

/**
 * The per-attempt flags of a checkout - "which contract does this shopper
 * have open", "was the payment step skipped", the challenge core uses as the
 * order id - without saying where they live.
 *
 * Sprint 15 / S2 (GRAPH-QL). Until 2026-10-06 these were `$_SESSION` keys,
 * so the checkout classes only worked inside a PHP session. The Twig / OPC
 * checkout keeps them there ({@see SessionCheckoutContext}); a headless
 * checkout (GraphQL Storefront, MCP) keeps them under an entered scope in
 * `oe_payments_sessions` ({@see PersistedCheckoutContext}). Consumers are
 * wired to {@see ScopedCheckoutContext} and never know which.
 *
 * Keys are the same strings the session used, so nothing a running shop has
 * in its sessions is lost at the upgrade.
 *
 * @since 3.0.0
 */
interface CheckoutContextInterface
{
    /**
     * What this context is keyed by: the shop session id, or the headless
     * scope id (a contract id, or a basket id before a contract exists).
     */
    public function getScopeId(): string;

    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    /**
     * Afterwards get($key) answers its default.
     */
    public function remove(string $key): void;
}
