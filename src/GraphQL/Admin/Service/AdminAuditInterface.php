<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

use Closure;

/**
 * Runs an operation so that exactly one audit row records it, whatever the outcome (the admin API's recorder).
 *
 * @since 1.5.0
 */
interface AdminAuditInterface
{
    /**
     * @param list<string>                   $changedFields names only; values are never recorded, except `amount`
     * @param Closure(): PaymentOperationResult $action
     */
    public function audited(string $operationName, string $orderId, array $changedFields, ?float $amount, Closure $action): PaymentOperationResult;
}
