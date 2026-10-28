<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

/**
 * Capture, refund and void of an order's payment for the admin API. Every call is audited, keyed for
 * idempotency and confined to the request shop; refund and capture demand a recent second factor.
 *
 * @since 1.5.0
 */
interface AdminPaymentOperationsInterface
{
    public function capture(string $orderId, ?float $amount, ?string $idempotencyKey, ?string $reason = null): PaymentOperationResult;

    public function refund(string $orderId, float $amount, ?string $reason, ?string $idempotencyKey, ?string $description = null): PaymentOperationResult;

    public function void(string $orderId, ?string $idempotencyKey, ?string $reason = null): PaymentOperationResult;
}
