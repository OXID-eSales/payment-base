<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Payment;

use RuntimeException;

/**
 * Another money operation of the order holds its lock.
 *
 * @since 1.7.0
 */
final class OrderLockUnavailableException extends RuntimeException
{
}
