<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Payment\Support;

use Closure;
use OxidEsales\PaymentBase\Payment\OrderLockInterface;
use OxidEsales\PaymentBase\Payment\OrderLockUnavailableException;

/**
 * The order lock as a test double: a flag that is true while work runs, and a switch that makes the order busy. While work runs the order is busy for everybody else, as a named lock
 * is for another connection.
 */
final class ControllableOrderLock implements OrderLockInterface
{
    public bool $held = false;
    public bool $busy = false;
    /** The lock does not exclude: what a caller sees when the order lock is not what stops it. */
    public bool $open = false;
    public int $acquired = 0;

    public function runLocked(string $orderId, Closure $work): mixed
    {
        if ($this->busy || ($this->held && !$this->open)) {
            throw new OrderLockUnavailableException('busy');
        }
        ++$this->acquired;
        $this->held = true;
        try {
            return $work();
        } finally {
            $this->held = false;
        }
    }
}
