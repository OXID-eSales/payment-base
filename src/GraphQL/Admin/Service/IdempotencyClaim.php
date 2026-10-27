<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

/**
 * The answer of OperationIdempotency::claim(): this call is the first (FRESH), the same call already
 * finished (COMPLETED) or is still running (IN_FLIGHT).
 *
 * @since 1.5.0
 */
final readonly class IdempotencyClaim
{
    public const FRESH = 'fresh';
    public const COMPLETED = 'completed';
    public const IN_FLIGHT = 'in_flight';

    public function __construct(public string $outcome, public string $key, public string $operation, public string $orderId)
    {
    }
}
