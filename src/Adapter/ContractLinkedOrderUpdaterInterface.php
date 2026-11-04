<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter;

/**
 * Mirrors a contract-side terminal transition onto the linked oxorder row, otherwise the admin
 * order list (which colours rows by OXTRANSSTATUS) shows cancelled or failed orders as still open.
 */
interface ContractLinkedOrderUpdaterInterface
{
    /** Ends the order as cancelled when the contract transitions to CANCELLED. */
    public function markCancelled(string $orderId): void;

    /** Ends the order as failed when the contract transitions to FAILED. */
    public function markFailed(string $orderId, string $reason): void;
}
