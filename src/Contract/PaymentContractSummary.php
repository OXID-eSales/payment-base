<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Contract;

use DateTimeImmutable;

/**
 * What a client may learn of the contract behind an order: its state, the
 * provider's id (`stripe`, `paypal`, `mollie`), the contract id and when it
 * last changed. No provider ids of the payment, no payloads.
 *
 * @since 1.4.0
 */
final readonly class PaymentContractSummary
{
    public function __construct(
        public string $contractId,
        public string $state,
        public string $provider,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
