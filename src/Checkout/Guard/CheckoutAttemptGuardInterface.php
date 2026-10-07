<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Guard;

use OxidEsales\PaymentBase\Adapter\Exception\ShopOrderException;
use OxidEsales\PaymentBase\Adapter\Request\CreateOrderRequest;

/**
 * One order per checkout attempt, for checkouts core cannot guard itself.
 *
 * Sprint 15 / S3 (GRAPH-QL). In the Twig checkout core refuses a second
 * submission: `sess_challenge` is the order id and Order::finalizeOrder()
 * answers ORDEREXISTS. A headless request has no session, so core mints a
 * fresh id every time and two concurrent CheckoutStart calls for one basket
 * would create two orders. The order service asks this guard before it does
 * anything, tells it the order id afterwards, and gives the claim back when
 * creation failed so the shopper can retry at once.
 *
 * @since 3.0.0
 */
interface CheckoutAttemptGuardInterface
{
    /**
     * Same code OxidShopOrderService answers for core's ORDEREXISTS: to the
     * caller a refused claim IS a second submission of one attempt.
     */
    public const ERROR_ORDER_EXISTS = 'order_exists';

    /**
     * @throws ShopOrderException with {@see self::ERROR_ORDER_EXISTS} when the
     *         same attempt is already in flight or just finished; its context
     *         names `order_id` (null while in flight), `user_id`, `basket_id`
     */
    public function claim(CreateOrderRequest $request): void;

    public function complete(CreateOrderRequest $request, string $orderId): void;

    public function release(CreateOrderRequest $request): void;
}
