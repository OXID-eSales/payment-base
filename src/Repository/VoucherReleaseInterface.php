<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Repository;

/**
 * Releases the voucher reservation an unfinished order was holding.
 *
 * Narrow on purpose: the abandoned-checkout collector needs the whole order
 * repository, but a webhook that merely learns a payment ended needs only
 * this. Splitting it keeps that consumer from depending on order queries it
 * has no business calling.
 *
 * @since STRP-168
 */
interface VoucherReleaseInterface
{
    /**
     * Clear the reservation that early order creation put on the order's
     * vouchers, so the customer can spend them again.
     *
     * @return int number of vouchers released
     */
    public function releaseVouchers(string $orderId): int;
}
