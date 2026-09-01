<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Contract;

/**
 * Moves the messages a PSP queued for the thank-you page out of the shop's
 * display-error stash, so the page can present them as notices.
 *
 * The shop offers one channel for saying anything to the customer between two
 * requests — `UtilsView::addErrorToDisplay()` — and paints everything in it as
 * a dismissible red alert above the page. On the thank-you page that is always
 * the wrong frame: the order exists by then, so nothing queued for it is an
 * error the customer can act on. A payment still settling is the common case.
 *
 * @since 2026-09-01
 */
interface CheckoutNoticeRelocatorInterface
{
    /**
     * Take the queued messages, translated, and leave the stash empty so the
     * shop has nothing left to paint red.
     *
     * Only the `default` destination is taken. `popup`, `loginBoxErrors` and
     * any custom destination are rendered elsewhere by whoever queued them.
     *
     * @return array<int, string> in the order they were queued
     */
    public function takeDisplayNotices(): array;
}
