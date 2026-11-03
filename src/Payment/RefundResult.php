<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Payment;

use InvalidArgumentException;

/**
 * What the PSP says a refund did: its id for the refund, the amount and the status. A `pending` refund is booked and
 * settles when the PSP reports it done; a `failed` one moved no money and is not booked.
 *
 * @since 1.7.0
 */
final readonly class RefundResult
{
    public const SUCCEEDED = 'succeeded';
    public const PENDING = 'pending';
    public const FAILED = 'failed';

    private const STATUSES = [self::SUCCEEDED, self::PENDING, self::FAILED];

    public function __construct(public string $providerRefundId, public float $amount, public string $status)
    {
        if ($providerRefundId === '') {
            throw new InvalidArgumentException('A refund result carries the PSP\'s refund id.');
        }
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Unknown refund status "' . $status . '".');
        }
    }
}
