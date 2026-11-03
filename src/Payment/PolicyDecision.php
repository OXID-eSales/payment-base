<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Payment;

/**
 * The answer of a money policy: the operation may run, or why not. The code is what a client switches on, the field
 * names the input at fault (`orderId` for the state of the payment itself).
 *
 * @since 1.7.0
 */
final readonly class PolicyDecision
{
    private function __construct(
        public bool $allowed,
        public string $field,
        public string $code,
        public string $message,
    ) {
    }

    public static function allow(): self
    {
        return new self(true, '', '', '');
    }

    public static function refuse(string $field, string $code, string $message): self
    {
        return new self(false, $field, $code, $message);
    }
}
