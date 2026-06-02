<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Service;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * Null-object default for {@see PaymentCaptureStatusQueryInterface}.
 *
 * Always returns `null` ("unknown"). Used as the safe fallback when
 * no provider-specific implementation is registered — keeps the
 * listener decoupled from missing wiring. Consumers treat `null`
 * as "fall back to the current (pre-disambiguation) behaviour".
 */
class UnknownPaymentCaptureStatusQuery implements PaymentCaptureStatusQueryInterface
{
    public function isPaymentCaptured(PaymentContractInterface $contract): ?bool
    {
        return null;
    }
}
