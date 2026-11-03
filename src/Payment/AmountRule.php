<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Payment;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Math\Money\MinorUnitConverter;
use OxidEsales\PaymentBase\Math\Money\Money;

/**
 * What every requested money amount must satisfy, whatever the operation: the contract's currency, a positive
 * finite number in that currency's minor unit, and not above what is left. One rule for capture and refund, so
 * the two cannot drift apart.
 *
 * @since 1.7.0
 */
final class AmountRule
{
    public static function check(
        PaymentContractInterface $contract,
        float $amount,
        string $currency,
        float $remaining,
        string $exceedsCode,
        string $exceedsMessage,
    ): PolicyDecision {
        if (strtoupper($currency) !== strtoupper($contract->getCurrency())) {
            return PolicyDecision::refuse('currency', 'currency_mismatch', 'The amount must be in the currency of the payment');
        }
        if (!is_finite($amount) || self::roundedTo($amount, $currency) <= 0.0) {
            return PolicyDecision::refuse('amount', 'amount_invalid', 'The amount must be a positive number');
        }

        return Money::greaterThan(self::roundedTo($amount, $currency), $remaining)
            ? PolicyDecision::refuse('amount', $exceedsCode, $exceedsMessage)
            : PolicyDecision::allow();
    }

    /**
     * The amount as the provider sees it: rounded to the minor unit of the currency.
     */
    public static function roundedTo(float $amount, string $currency): float
    {
        return MinorUnitConverter::toMajorUnits(MinorUnitConverter::toMinorUnits($amount, $currency), $currency);
    }
}
