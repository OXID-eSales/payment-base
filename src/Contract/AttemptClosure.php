<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Contract;

/**
 * What the provider says about the payment page of the attempt the customer
 * leaves (OPC-175).
 *
 * - Paid: the customer has paid; the attempt is not replaced.
 * - Closed: the page can take no more money.
 * - Open: the provider keeps the page open (it cannot be cancelled); money
 *   that arrives on it later is refunded.
 *
 * @since OPC-175
 */
enum AttemptClosure: string
{
    case Paid = 'paid';
    case Closed = 'closed';
    case Open = 'open';
}
