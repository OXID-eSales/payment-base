<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Payment;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * One refund the PSP is asked for. `providerCaptureId` names the capture to refund where the provider keeps several
 * (PayPal); null lets the provider choose.
 *
 * @since 1.7.0
 */
final readonly class RefundRequest
{
    public function __construct(
        public PaymentContractInterface $contract,
        public float $amount,
        public string $currency,
        public string $idempotencyKey,
        public ?string $providerCaptureId = null,
        public ?string $reason = null,
        public ?string $description = null,
    ) {
    }
}
