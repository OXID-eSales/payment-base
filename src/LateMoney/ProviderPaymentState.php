<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\LateMoney;

/**
 * What a provider's payment holds, in provider-neutral words.
 *
 * - Paid: the money was captured; it leaves again by a refund.
 * - Authorized: the money is only held; it leaves by releasing the authorization.
 * - NoMoney: nothing is held (open, failed, cancelled, expired); nothing to give back.
 *
 * @since 1.8.0
 */
enum ProviderPaymentState: string
{
    case Paid = 'paid';
    case Authorized = 'authorized';
    case NoMoney = 'no_money';
}
