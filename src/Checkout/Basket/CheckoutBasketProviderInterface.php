<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Basket;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\PaymentBase\Adapter\Exception\ShopOrderException;
use OxidEsales\PaymentBase\Adapter\Request\CreateOrderRequest;

/**
 * Where the basket of a checkout attempt comes from.
 *
 * Sprint 15 / S1 (GRAPH-QL). Until 2026-10-06 OxidShopOrderService read
 * `Registry::getSession()->getBasket()` itself, so an order could only be
 * finalized inside a PHP session - the Twig and OPC checkouts. The GraphQL
 * Storefront has no session (JWT identity, basket persisted as an
 * `oxuserbaskets` row addressed by id) and neither has the MCP layer. The
 * service now asks this provider and does not know where baskets live.
 *
 * @since 3.0.0
 */
interface CheckoutBasketProviderInterface
{
    /**
     * The calculated shop basket for this request, or null when there is none
     * (the service then answers `basket_not_found`).
     *
     * @throws ShopOrderException when a basket exists but may not be used for
     *         this request (another user's basket, owner not loadable)
     */
    public function basketFor(CreateOrderRequest $request): ?Basket;
}
