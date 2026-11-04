<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Reconciliation;

interface OxpaidReconciliationServiceInterface
{
    /** @return list<string> providers that registered a status port */
    public function providers(): array;

    /**
     * Orders of the provider with OXPAID unset, created within the age, that have a contract.
     *
     * @return list<array<string, mixed>> rows OXID, OXORDERNR, OXORDERDATE, OXPROVIDERORDERID
     */
    public function findUnpaidOrders(string $provider, int $maxAgeDays): array;

    /** Heals OXPAID of one order from the provider's payment status and fulfils its contract. */
    public function reconcileOrder(string $orderId): ReconciliationResult;

    /** @return list<ReconciliationResult> */
    public function reconcileAll(string $provider, int $maxAgeDays, bool $dryRun): array;
}
