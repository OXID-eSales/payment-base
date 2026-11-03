<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\AtRisk;

use DateTimeImmutable;

/**
 * One open "payment at risk" flag: a reason on a contract, for the provider reference that caused it (the
 * dispute, the denied capture, the late payment; empty when the reason has none).
 *
 * @since 1.8.0
 */
final readonly class PaymentAtRisk
{
    public function __construct(
        public string $id,
        public string $contractId,
        public ?string $orderId,
        public AtRiskReason $reason,
        public string $reference,
        public DateTimeImmutable $raisedAt,
    ) {
    }
}
