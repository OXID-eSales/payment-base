<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Service;

use InvalidArgumentException;
use OxidEsales\PaymentBase\Service\Result\NotFinishedOrderCleanupResult;

/**
 * Collects orders that the shop created just before handing the customer to a
 * payment provider, where the customer never came back.
 *
 * Provider-agnostic on purpose: every PSP module using the early-order pattern
 * leaves the same residue, so the collector belongs here rather than in any
 * one of them.
 */
interface NotFinishedOrderCleanupServiceInterface
{
    /**
     * @param int      $days   only touch orders older than this; must be >= 1
     * @param bool     $dryRun report what would be cleaned, write nothing
     * @param int|null $shopId restrict to one subshop, or null for all shops
     * @param int|null $limit  cap the batch, or null for no cap
     *
     * @throws InvalidArgumentException when $days is below 1
     */
    public function cleanup(
        int $days,
        bool $dryRun = false,
        ?int $shopId = null,
        ?int $limit = null
    ): NotFinishedOrderCleanupResult;
}
