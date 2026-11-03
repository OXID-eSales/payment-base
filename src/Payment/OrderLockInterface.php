<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Payment;

use Closure;

/**
 * Serialises the money operations of one order: whatever the client's idempotency keys, only one capture, refund
 * or void of an order runs at a time. Two calls with different keys would otherwise both pass the bound check
 * before either has booked.
 *
 * @since 1.7.0
 */
interface OrderLockInterface
{
    /**
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     *
     * @throws OrderLockUnavailableException the order is busy and stayed busy for the whole wait
     */
    public function runLocked(string $orderId, Closure $work): mixed;
}
