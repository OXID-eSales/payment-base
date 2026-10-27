<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support;

use Closure;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminAuditInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentOperationResult;
use Throwable;

final class RecordingAudit implements AdminAuditInterface
{
    /** @var list<array{operation: string, orderId: string, fields: list<string>, amount: ?float, outcome: string}> */
    public array $rows = [];

    public function audited(string $operationName, string $orderId, array $changedFields, ?float $amount, Closure $action): PaymentOperationResult
    {
        try {
            $result = $action();
        } catch (Throwable $thrown) {
            $this->rows[] = $this->row($operationName, $orderId, $changedFields, $amount, 'threw:' . $thrown::class);
            throw $thrown;
        }
        $refusal = $result->refusal();
        $this->rows[] = $this->row($operationName, $orderId, $changedFields, $amount, $refusal === null ? 'success' : $refusal->code);

        return $result;
    }

    /**
     * @param list<string> $fields
     *
     * @return array{operation: string, orderId: string, fields: list<string>, amount: ?float, outcome: string}
     */
    private function row(string $operation, string $orderId, array $fields, ?float $amount, string $outcome): array
    {
        return ['operation' => $operation, 'orderId' => $orderId, 'fields' => $fields, 'amount' => $amount, 'outcome' => $outcome];
    }
}
