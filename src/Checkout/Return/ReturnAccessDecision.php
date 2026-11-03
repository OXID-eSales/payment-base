<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Return;

final readonly class ReturnAccessDecision
{
    private function __construct(
        public bool $granted,
        public ?ReturnAccessRejection $rejection,
    ) {
    }

    public static function grant(): self
    {
        return new self(true, null);
    }

    public static function reject(ReturnAccessRejection $rejection): self
    {
        return new self(false, $rejection);
    }
}
