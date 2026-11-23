<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Attempt;

use RuntimeException;

/**
 * MOL-39: another request creates the order of this contract right now. This request created nothing.
 * The caller tells the shopper that the order is being processed; it does not start a payment.
 *
 * @since sprint 143 (AW)
 */
final class OrderCreationAlreadyClaimedException extends RuntimeException
{
    public static function forContract(string $contractId): self
    {
        return new self('Another request creates the order of contract ' . $contractId);
    }
}
