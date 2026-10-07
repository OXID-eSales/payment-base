<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Basket;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentBase\Adapter\Request\CreateOrderRequest;

/**
 * The Twig / OPC checkout: the basket core keeps in the PHP session.
 *
 * This is byte-for-byte what OxidShopOrderService::sessionBasket() did before
 * Sprint 15; it only moved. The request is not consulted - the session is the
 * request in this world.
 *
 * @since 3.0.0
 */
final class SessionBasketProvider implements CheckoutBasketProviderInterface
{
    public function basketFor(CreateOrderRequest $request): ?Basket
    {
        /** @var Basket|null $basket */
        $basket = Registry::getSession()->getBasket();

        return $basket;
    }
}
