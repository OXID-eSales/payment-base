<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Return;

/**
 * One signing secret of the return token and the id the token names it by.
 */
final readonly class ReturnTokenSecret
{
    public function __construct(
        public string $keyId,
        public string $secret,
    ) {
    }
}
