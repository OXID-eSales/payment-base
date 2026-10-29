<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Attempt;

use RuntimeException;

/**
 * The customer starts a new payment for an attempt the provider reports as
 * paid. The attempt is not replaced; the provider's confirmation completes it.
 *
 * @since OPC-175
 */
final class CheckoutAttemptAlreadyPaidException extends RuntimeException
{
    public static function forContract(string $contractId): self
    {
        return new self('The checkout attempt is already paid: contract ' . $contractId);
    }
}
