<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Headless;

/**
 * @since 3.0.0
 */
final readonly class HeadlessCancelResult
{
    public function __construct(
        public bool $cancelled,
        public string $contractId,
        public string $contractState,
    ) {
    }
}
