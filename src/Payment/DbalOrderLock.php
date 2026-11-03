<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Payment;

use Closure;
use Doctrine\DBAL\Connection;

/**
 * The order lock as a MySQL named lock (`GET_LOCK('pb_order_<digest>')`), held on the connection that runs the work
 * and gone with it should the process die. The name is a digest of the order id: a client's text never reaches the
 * 64-character limit of lock names.
 *
 * @since 1.7.0
 */
final class DbalOrderLock implements OrderLockInterface
{
    private const NAME_PREFIX = 'pb_order_';
    private const WAIT_SECONDS = 10;

    public function __construct(private readonly Connection $connection, private readonly int $waitSeconds = self::WAIT_SECONDS)
    {
    }

    public function runLocked(string $orderId, Closure $work): mixed
    {
        $name = self::NAME_PREFIX . md5($orderId);
        if ((int) $this->connection->fetchOne('SELECT GET_LOCK(:name, :wait)', ['name' => $name, 'wait' => $this->waitSeconds]) !== 1) {
            throw new OrderLockUnavailableException('Another money operation of order ' . $orderId . ' is running.');
        }
        try {
            return $work();
        } finally {
            $this->connection->fetchOne('SELECT RELEASE_LOCK(:name)', ['name' => $name]);
        }
    }
}
