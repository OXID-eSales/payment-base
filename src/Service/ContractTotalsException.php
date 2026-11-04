<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Service;

use RuntimeException;

/**
 * The basket has no amount a payment contract can be made for. The message is the reason a
 * checkout is refused with; the payment handlers of the providers turn it into their normal
 * "payment not possible" result.
 *
 * @since VR-26
 */
final class ContractTotalsException extends RuntimeException
{
    public static function noPrice(): self
    {
        return new self('Cannot create a payment contract: the basket has no price.');
    }

    public static function zeroTotal(): self
    {
        return new self('Cannot create a payment contract: the basket total leaves nothing to charge.');
    }
}
