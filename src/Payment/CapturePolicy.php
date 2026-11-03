<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Payment;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Math\Money\Money;

/**
 * Capturable: the contract is authorised, or committed by a manual-capture provider, and part of the authorised
 * amount is not captured yet. The provider validates again; this is the bound the admin may offer and enforce.
 *
 * @since 1.7.0
 */
final class CapturePolicy implements CapturePolicyInterface
{
    private const CAPTURABLE_STATES = ['authorized', 'committed'];

    public function remaining(PaymentContractInterface $contract): float
    {
        if (!in_array($contract->getStateValue(), self::CAPTURABLE_STATES, true)) {
            return 0.0;
        }
        $rest = $contract->getAmount() - ($contract->getCapturedAmount() ?? 0.0);

        return $rest < Money::HALF_CENT_EPSILON ? 0.0 : $rest;
    }

    public function decide(PaymentContractInterface $contract, float $amount, string $currency): PolicyDecision
    {
        $remaining = $this->remaining($contract);
        if ($remaining <= 0.0) {
            return PolicyDecision::refuse('orderId', 'not_capturable', 'This payment is not an open authorisation: there is nothing to capture');
        }

        return AmountRule::check($contract, $amount, $currency, $remaining, 'exceeds_capturable', 'The amount is more than the authorisation holds');
    }
}
