<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\User;

/**
 * An order that can be written again from the current basket (payment-base's
 * Order extension). The parts that need core's protected order methods live
 * there; the order service decides when.
 *
 * @since OPC-175
 */
interface RewritableOrderInterface
{
    /**
     * Replace lines, payment, totals and vouchers with the basket's; keep id and
     * order number; leave the order NOT_FINISHED; render the order mails again
     * on the capturing mailer.
     *
     * @return int core's order state (Order::ORDER_STATE_OK on success)
     */
    public function rewriteFromBasket(Basket $basket, User $user): int;
}
