<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\AtRisk;

/**
 * What the admin reads: the open flags of one contract (the badge) and every order that has some (the filter).
 *
 * @since 1.8.0
 */
interface PaymentAtRiskQueryInterface
{
    /**
     * @return list<PaymentAtRisk> oldest first
     */
    public function openForContract(string $contractId): array;

    /**
     * @return list<PaymentAtRisk> oldest first
     */
    public function openForOrder(string $orderId): array;

    /**
     * @return list<PaymentAtRisk> one entry per open flag, newest first, only flags that name an order
     */
    public function openForOrders(int $limit): array;
}
