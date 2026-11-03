<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Payment;

use InvalidArgumentException;

/**
 * What the PSP says a capture did: its id for the capture and the amount it took.
 *
 * @since 1.7.0
 */
final readonly class CaptureResult
{
    public function __construct(public string $providerCaptureId, public float $amount)
    {
        if ($providerCaptureId === '') {
            throw new InvalidArgumentException('A capture result carries the PSP\'s capture id.');
        }
    }
}
