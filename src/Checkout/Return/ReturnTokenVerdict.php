<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Return;

/**
 * What a return token says about one contract: Expired is only ever answered for a token whose
 * signature, contract and shopper are right.
 */
enum ReturnTokenVerdict
{
    case Valid;
    case Expired;
    case Invalid;
}
