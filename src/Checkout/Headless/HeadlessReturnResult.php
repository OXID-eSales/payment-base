<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Headless;

/**
 * @param string $status `committed` | `pending` | `failed`
 *
 * @since 3.0.0
 */
final readonly class HeadlessReturnResult
{
    public const COMMITTED = 'committed';

    public const PENDING = 'pending';

    public const FAILED = 'failed';

    public function __construct(
        public string $status,
        public ?string $orderId,
        public ?string $orderNumber,
        public string $contractState,
    ) {
    }
}
