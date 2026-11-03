<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Payment;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * One capture the PSP is asked for. The idempotency key is stable across retries of the same admin call: the gateway
 * hands it to the PSP so that a retry after a timeout cannot capture twice.
 *
 * @since 1.7.0
 */
final readonly class CaptureRequest
{
    public function __construct(
        public PaymentContractInterface $contract,
        public float $amount,
        public string $currency,
        public string $idempotencyKey,
        public ?string $reason = null,
    ) {
    }
}
