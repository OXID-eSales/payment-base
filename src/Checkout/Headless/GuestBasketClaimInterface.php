<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Headless;

use OxidEsales\PaymentBase\Adapter\Exception\ShopOrderException;

/**
 * Hands the basket an anonymous shopper built over to the guest account the
 * checkout runs as. An anonymous basket has no owner row; the checkout may
 * only be run by its owner (UserBasketProvider), so the guest becomes it.
 *
 * @since 1.5.0
 */
interface GuestBasketClaimInterface
{
    /**
     * Idempotent for the same guest.
     *
     * @throws ShopOrderException `basket_forbidden` when the basket already belongs to somebody else
     */
    public function claim(string $basketId, GuestUser $guest): void;
}
