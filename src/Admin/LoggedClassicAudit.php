<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Admin;

use Closure;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminAuditInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentOperationResult;
use OxidEsales\PaymentBase\Payment\ClassicAdminSessionInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The classic admin's audit of a money action: one structured log entry per call, whatever the outcome. The same
 * facts the admin API's audit row holds: who, what, which order, the names of the fields and the amount.
 *
 * @since 1.7.0
 */
final class LoggedClassicAudit implements AdminAuditInterface
{
    public function __construct(
        private readonly ClassicAdminSessionInterface $session,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function audited(string $operationName, string $orderId, array $changedFields, ?float $amount, Closure $action): PaymentOperationResult
    {
        try {
            $result = $action();
        } catch (Throwable $thrown) {
            $this->logger->warning('Classic admin payment action', $this->entry($operationName, $orderId, $changedFields, $amount, 'threw:' . $thrown::class));

            throw $thrown;
        }
        $this->logger->info('Classic admin payment action', $this->entry($operationName, $orderId, $changedFields, $amount, $result->refusal()->code ?? 'success'));

        return $result;
    }

    /**
     * @param list<string> $changedFields
     *
     * @return array<string, mixed>
     */
    private function entry(string $operationName, string $orderId, array $changedFields, ?float $amount, string $outcome): array
    {
        return [
            'admin' => $this->session->userId(),
            'operation' => $operationName,
            'orderId' => $orderId,
            'fields' => $changedFields,
            'amount' => $amount,
            'outcome' => $outcome,
        ];
    }
}
