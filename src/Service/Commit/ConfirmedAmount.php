<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Service\Commit;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * What the provider confirms must be what the contract charges - the one rule
 * both commit legs apply: the webhook (ContractCommitService) and the browser
 * return (CheckoutReturnResponder). Before VR-20 only the webhook leg checked.
 *
 * Static pure function, no state (the idiom of ShopCurrency / AmountConverter).
 */
final class ConfirmedAmount
{
    /** Float noise below half a cent; a real difference is at least one cent. */
    private const TOLERANCE = 0.005;

    public static function matches(PaymentContractInterface $contract, float $amount, string $currency): bool
    {
        return abs($contract->getAmount() - $amount) <= self::TOLERANCE
            && strtoupper($contract->getCurrency()) === strtoupper($currency);
    }
}
