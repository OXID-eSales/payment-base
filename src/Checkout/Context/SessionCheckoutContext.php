<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Context;

use OxidEsales\PaymentBase\Adapter\SessionAdapterInterface;

/**
 * The Twig / OPC checkout's context: the shop session, under the same keys
 * the checkout classes wrote before Sprint 15. Byte-identical by design.
 *
 * @since 3.0.0
 */
final class SessionCheckoutContext implements CheckoutContextInterface
{
    public function __construct(private readonly SessionAdapterInterface $session)
    {
    }

    public function getScopeId(): string
    {
        return $this->session->getSessionId();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->session->getVariable($key) ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->session->setVariable($key, $value);
    }

    /**
     * SessionAdapterInterface has no delete; writing null is how the registry
     * always forgot, and every reader treats null as unset.
     */
    public function remove(string $key): void
    {
        $this->session->setVariable($key, null);
    }
}
