<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Payment;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * The release of an open authorisation at the PSP.
 *
 * @since 1.7.0
 */
final readonly class CancelRequest
{
    public function __construct(
        public PaymentContractInterface $contract,
        public string $idempotencyKey,
        public ?string $reason = null,
    ) {
    }
}
