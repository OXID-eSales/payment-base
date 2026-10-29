<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter;

/**
 * OPC-175 / S4 (G9) — an ended checkout order gives its stock back.
 *
 * The early order took the stock in finalizeOrder(). The guarded storno of an
 * unfinished order (cleanup command, deleteNotFinishedOrder()) is plain SQL and
 * did not return it.
 *
 * @since OPC-175
 */
interface OrderRestockInterface
{
    /**
     * Cancels each order article that is not cancelled yet and returns its
     * amount to the stock (core OrderArticle::cancelOrderArticle()).
     */
    public function restock(string $orderId): void;
}
