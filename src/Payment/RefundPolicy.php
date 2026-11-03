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
 * Refundable: the contract is committed or fulfilled and the captured amount exceeds the refunded one. A contract
 * without a reported capture has nothing to refund: the capture's evidence has to arrive first.
 *
 * @since 1.7.0
 */
final class RefundPolicy implements RefundPolicyInterface
{
    private const REFUNDABLE_STATES = ['committed', 'fulfilled'];

    public function remaining(PaymentContractInterface $contract): float
    {
        if (!in_array($contract->getStateValue(), self::REFUNDABLE_STATES, true)) {
            return 0.0;
        }
        $rest = ($contract->getCapturedAmount() ?? 0.0) - ($contract->getRefundedAmount() ?? 0.0);

        return $rest < Money::HALF_CENT_EPSILON ? 0.0 : $rest;
    }

    public function decide(PaymentContractInterface $contract, float $amount, string $currency): PolicyDecision
    {
        $remaining = $this->remaining($contract);
        if ($remaining <= 0.0) {
            return PolicyDecision::refuse('orderId', 'not_refundable', 'There is nothing left to refund on this payment');
        }

        return AmountRule::check($contract, $amount, $currency, $remaining, 'exceeds_refundable', 'The amount is more than what is left to refund');
    }
}
