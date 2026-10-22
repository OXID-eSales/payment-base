<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Headless;

/**
 * Turns an anonymous shopper's details into the shop user a checkout runs as.
 * Implemented in Adapter/ (the only place that touches the shop's User model).
 *
 * @since 1.5.0
 */
interface GuestUserServiceInterface
{
    /**
     * A new guest account (no password), or the existing guest account of that
     * e-mail with its addresses brought up to date.
     *
     * @throws HeadlessCheckoutException `account_exists` when the e-mail belongs to a registered account,
     *         `guest_details_required` when the shop does not know the country
     */
    public function userFor(GuestDetails $details): GuestUser;
}
