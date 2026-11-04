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
 * The one session adapter (VR-25 D0): payment-base binds {@see SessionAdapterInterface} to it and
 * the provider modules bind nothing.
 *
 * @since STRP-171
 */
class OxidSessionAdapter implements SessionAdapterInterface
{
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
}
