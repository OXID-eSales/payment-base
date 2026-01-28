<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Service;

/**
 * Interface for stock restoration on refund.
 *
 * Sprint 24: Extracted from OXID's OrderArticle::storno() logic.
 * When a full refund is processed, this service restores stock for all
 * order articles and marks them as cancelled (storno).
 *
 * @since 2.0.0
 */
interface StockRestorationServiceInterface
{
    /**
     * Restore stock for all articles in an order.
     *
     * - Marks all order articles as storno (oxstorno = 1)
     * - Restores stock for each article (if blUseStock is enabled)
     * - Recalculates order totals
     * - Skips articles that are already storno'd
     *
     * @param string $orderId The order ID to process
     * @return int Number of articles processed (not including already storno'd)
     */
    public function restoreStockForOrder(string $orderId): int;
}
