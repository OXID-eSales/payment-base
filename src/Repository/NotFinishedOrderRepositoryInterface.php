<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Repository;

/**
 * Order-table access for the abandoned-checkout collector.
 *
 * Deliberately keyed on `oxorder` rather than on `oe_payments_contract`: an
 * early order can outlive the contract that created it (or never be pointed at
 * by one), and those rows are precisely the ones nothing else reclaims.
 */
interface NotFinishedOrderRepositoryInterface extends VoucherReleaseInterface
{
    /**
     * Orders still sitting at OXTRANSSTATUS = 'NOT_FINISHED', not yet stornoed,
     * and older than the given number of days. Oldest first.
     *
     * @param int      $days   age threshold; must be >= 1
     * @param int|null $shopId restrict to one subshop, or null for all shops
     * @param int|null $limit  cap the batch, or null for no cap
     *
     * @return array<int, string> order OXIDs
     */
    public function findStaleNotFinishedOrderIds(int $days, ?int $shopId = null, ?int $limit = null): array;

    /**
     * Storno the order and mark it CANCELLED, preserving the row.
     *
     * The order is kept (rather than deleted) so the order-number sequence has
     * no gaps, and the status moves off NOT_FINISHED so OXID's checkOrderExist()
     * stops treating it as a live order for the same session challenge.
     *
     * The write is guarded on the status still being NOT_FINISHED, so an order
     * that got finished between the query and this call is left untouched.
     *
     * @return bool true if this call is what cancelled the order
     */
    public function cancelOrder(string $orderId): bool;
}
