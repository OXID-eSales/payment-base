<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

use OxidEsales\PaymentBase\Payment\AdminChannelInterface;
use OxidEsales\PaymentBase\Payment\MoneyOperationRunnerInterface;

/**
 * The admin API's capture, refund and void: the mutations' arguments handed to the one money path through the admin
 * API's door. Everything that decides whether and how the money moves is the path's (VR-22 A3); nothing is left here
 * but the translation of the call.
 *
 * @since 1.5.0
 */
final class AdminPaymentOperations implements AdminPaymentOperationsInterface
{
    public function __construct(
        private readonly MoneyOperationRunnerInterface $runner,
        private readonly AdminChannelInterface $channel,
    ) {
    }

    public function capture(string $orderId, ?float $amount, ?string $idempotencyKey, ?string $reason = null): PaymentOperationResult
    {
        return $this->runner->run(AdminPaymentOperation::capture($orderId, $amount, $idempotencyKey, $reason), $this->channel);
    }

    public function refund(string $orderId, float $amount, ?string $reason, ?string $idempotencyKey, ?string $description = null): PaymentOperationResult
    {
        return $this->runner->run(AdminPaymentOperation::refund($orderId, $amount, $reason, $idempotencyKey, $description), $this->channel);
    }

    public function void(string $orderId, ?string $idempotencyKey, ?string $reason = null): PaymentOperationResult
    {
        return $this->runner->run(AdminPaymentOperation::void($orderId, $idempotencyKey, $reason), $this->channel);
    }
}
