<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter;

use OxidEsales\Eshop\Core\Registry;

/**
 * OXID implementation of {@see SessionAdapterInterface}.
 *
 * The provider modules each ship their own copy of this and bind the interface
 * to it. payment-base needs a session of its own for the retry cleanup, and
 * binding the interface here as well would only add a fourth definition of one
 * container id. So this one is wired by its concrete class instead, which is
 * why it collides with nothing.
 *
 * @since STRP-171
 */
class OxidSessionAdapter implements SessionAdapterInterface
{
    public function getSessionId(): string
    {
        return (string) Registry::getSession()->getId();
    }

    /** @phpstan-ignore return.unusedType (interface allows null, OXID always returns a basket) */
    public function getBasket(): ?object
    {
        return Registry::getSession()->getBasket();
    }

    public function setVariable(string $name, mixed $value): void
    {
        Registry::getSession()->setVariable($name, $value);
    }

    public function getVariable(string $name): mixed
    {
        return Registry::getSession()->getVariable($name);
    }

    public function setBasket(object $basket): void
    {
        Registry::getSession()->setBasket($basket);
    }

    public function setUser(object $user): void
    {
        Registry::getSession()->setVariable('oePaymentUser', $user);
    }
}
