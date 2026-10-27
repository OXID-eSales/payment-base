<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

use Closure;
use LogicException;
use OxidEsales\GraphQL\Admin\Audit\DataType\AuditTrail;
use OxidEsales\GraphQL\Admin\Audit\Service\AuditRecorder;
use OxidEsales\PaymentBase\GraphQL\Admin\DataType\AuditedPaymentOutcome;

/**
 * The admin module's audit recorder (G05) for the money operations: one row per call, entity `order`, the names of
 * the fields the call carried and the amount as the only value (the audit allow-list; the free-text reason is a
 * field name, never a value). The module is optional: wired with `@?`, and without it the operation fails closed.
 *
 * @since 1.5.0
 */
final class OxidAdminAudit implements AdminAuditInterface
{
    private const ENTITY = 'order';

    public function __construct(private readonly ?AuditRecorder $recorder)
    {
    }

    public function audited(string $operationName, string $orderId, array $changedFields, ?float $amount, Closure $action): PaymentOperationResult
    {
        if ($this->recorder === null) {
            throw new LogicException('The admin API is not installed: there is no audit log to write');
        }
        $result = null;
        $this->recorder->auditedAdmin(
            $operationName,
            static function (AuditTrail $trail) use ($orderId, $changedFields, $amount, $action, &$result): AuditedPaymentOutcome {
                $trail->on(self::ENTITY, $orderId)->field(...$changedFields);
                if ($amount !== null && is_finite($amount)) {
                    $trail->value('amount', $amount);
                }
                $result = $action();

                return new AuditedPaymentOutcome($result);
            },
        );

        return $result ?? throw new LogicException('The audited operation did not run');
    }
}
