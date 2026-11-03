<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\LateMoney;

/**
 * @since 1.8.0
 */
enum LateMoneyKind: string
{
    case Refunded = 'refunded';
    case Released = 'released';
    /** The payment holds no money (yet); nothing was recorded, a later delivery is handled. */
    case NoMoney = 'no_money';
    /** An earlier delivery already gave this money back. */
    case AlreadySettled = 'already_settled';
    /** The provider refused to give it back; the order is flagged and a later delivery retries. */
    case Failed = 'failed';
}
