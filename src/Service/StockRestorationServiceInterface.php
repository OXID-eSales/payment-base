<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Service;

/**
 * Stock restoration of a fully refunded order.
 *
 * VR-21 F3: one method, one meaning. The caller decides when (see Refund\RefundSettledPolicy: a succeeded refund
 * of the last refundable amount); the implementation restores once per order, so asking twice is harmless.
 * Replaces `restoreStockForOrder()` of Sprint 24; the implementation is payment-base's
 * Stock\OxidStockRestorationService, no provider module ships its own.
 *
 * @since 2.0.0
 */
interface StockRestorationServiceInterface
{
    /**
     * Restores the stock of every order article that is not storno'd yet (when the shop manages stock) and
     * storno's it. Order totals stay as they are.
     *
     * @param string $orderId The order ID to process
     * @return int Number of articles processed (0: order unknown, restored before, or nothing left to restore)
     */
    public function restoreForFullRefund(string $orderId): int;
}
