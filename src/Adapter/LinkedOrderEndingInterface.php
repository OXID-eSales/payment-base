<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter;

/**
 * OPC-175 / S4 — the order of a contract that ended at the provider
 * (cancelled, failed) ends with it, the same way for every provider.
 *
 * @since OPC-175
 */
interface LinkedOrderEndingInterface
{
    public const STATUS_CANCELLED = 'CANCELLED';
    public const STATUS_FAILED = 'FAILED';

    /**
     * Records the status on the order. An unpaid order is also cancelled the
     * shop's way: storno, stock back, vouchers back. A paid order keeps its
     * money, stock and vouchers. An order that does not exist is left alone.
     */
    public function end(string $orderId, string $transStatus): void;
}
