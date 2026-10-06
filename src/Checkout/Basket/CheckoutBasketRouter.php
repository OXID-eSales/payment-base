<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Basket;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\PaymentBase\Adapter\Request\CreateOrderRequest;

/**
 * Tells a headless checkout from a session checkout by one fact: whether the
 * request names a basket id.
 *
 * No fallback in either direction. A request that names a basket which does
 * not exist is "not found" - serving the session basket instead would create
 * an order for a basket the caller never meant. A request without a basket id
 * never touches `oxuserbaskets`, so the Twig checkout costs nothing extra.
 *
 * @since 3.0.0
 */
final class CheckoutBasketRouter implements CheckoutBasketProviderInterface
{
    public function __construct(
        private readonly CheckoutBasketProviderInterface $sessionBaskets,
        private readonly CheckoutBasketProviderInterface $userBaskets
    ) {
    }

    public function basketFor(CreateOrderRequest $request): ?Basket
    {
        if ($request->basketId === null) {
            return $this->sessionBaskets->basketFor($request);
        }

        return $this->userBaskets->basketFor($request);
    }
}
