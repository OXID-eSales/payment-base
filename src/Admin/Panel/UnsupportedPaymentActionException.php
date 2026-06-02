<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Admin\Panel;

/**
 * Thrown when the admin tries to dispatch an action for an order that
 * no registered panel provider supports.
 */
class UnsupportedPaymentActionException extends \RuntimeException
{
}
