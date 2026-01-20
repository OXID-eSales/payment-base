<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Service\Result;

use DateTimeImmutable;

/**
 * Result of a payment refund operation.
 *
 * Sprint 3: Immutable value object returned by AbstractPaymentRefundService.
 *
 * @since 1.0.0
 */
final readonly class RefundResult
{
    /**
     * @param string $refundId Provider's refund ID
     * @param float $amountRefunded Amount that was refunded
     * @param string $currency Currency code (EUR, USD, etc.)
     * @param float $totalRefunded Total amount refunded for this contract (including this refund)
     * @param float $availableForRefund Remaining amount that can still be refunded
     * @param DateTimeImmutable $refundedAt When the refund occurred
     * @param array<string, mixed> $providerData Additional provider-specific data
     */
    public function __construct(
        public string $refundId,
        public float $amountRefunded,
        public string $currency,
        public float $totalRefunded,
        public float $availableForRefund,
        public DateTimeImmutable $refundedAt,
        public array $providerData = []
    ) {
    }
}
