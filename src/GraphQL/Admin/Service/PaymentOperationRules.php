<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

use OxidEsales\PaymentBase\Admin\Contract\AdminActionOptions;
use OxidEsales\PaymentBase\Math\Money\Money;

/**
 * What the payment may still be asked to do: the state and the amount limits of capture, refund and void.
 * The provider validates again with its own sources; these are the bounds the admin API offers and enforces
 * before any provider is called.
 *
 * @since 1.5.0
 */
final class PaymentOperationRules
{
    public static function refusalFor(AdminPaymentOperation $operation, PaymentContractDetails $payment): ?PaymentRefusal
    {
        return self::textRefusal($operation, $payment->actionOptions()) ?? match ($operation->kind) {
            AdminPaymentOperation::CAPTURE => self::capture($operation, $payment),
            AdminPaymentOperation::REFUND => self::refund($operation, $payment),
            default => self::void($payment),
        };
    }

    /**
     * The provider's own say on the free text: its limit, and, where it declares a list, the only reasons it takes.
     */
    private static function textRefusal(AdminPaymentOperation $operation, AdminActionOptions $options): ?PaymentRefusal
    {
        $limit = $options->reasonMaxLength;
        foreach (['reason' => $operation->reason, 'description' => $operation->description] as $field => $text) {
            if ($text !== null && strlen($text) > $limit) {
                return PaymentRefusal::invalid($field, 'too_long', sprintf('The %s is longer than %d characters', $field, $limit));
            }
        }
        $allowed = match ($operation->kind) {
            AdminPaymentOperation::REFUND => $options->refundReasons,
            AdminPaymentOperation::VOID => $options->voidReasons,
            default => [],
        };
        if ($operation->reason === null || $allowed === [] || in_array($operation->reason, $allowed, true)) {
            return null;
        }

        return PaymentRefusal::invalid('reason', 'reason_invalid', 'The reason is not one the payment provider accepts');
    }

    private static function capture(AdminPaymentOperation $operation, PaymentContractDetails $payment): ?PaymentRefusal
    {
        $capturable = $payment->amountCapturable();
        if ($capturable <= 0.0) {
            return PaymentRefusal::invalid('orderId', 'not_capturable', 'This payment is not an open authorisation: there is nothing to capture');
        }

        return self::amountRefusal($operation->amount, $capturable, $payment, 'exceeds_capturable', 'The amount is more than the authorisation holds', allowOmitted: true);
    }

    private static function refund(AdminPaymentOperation $operation, PaymentContractDetails $payment): ?PaymentRefusal
    {
        $refundable = $payment->amountRefundable();
        if ($refundable <= 0.0) {
            return PaymentRefusal::invalid('orderId', 'not_refundable', 'There is nothing left to refund on this payment');
        }

        return self::amountRefusal($operation->amount, $refundable, $payment, 'exceeds_refundable', 'The amount is more than what is left to refund', allowOmitted: false);
    }

    private static function void(PaymentContractDetails $payment): ?PaymentRefusal
    {
        return $payment->amountCapturable() > 0.0
            ? null
            : PaymentRefusal::invalid('orderId', 'not_voidable', 'Only an open authorisation can be voided; refund a captured payment instead');
    }

    private static function amountRefusal(
        ?float $amount,
        float $limit,
        PaymentContractDetails $payment,
        string $exceedsCode,
        string $exceedsMessage,
        bool $allowOmitted,
    ): ?PaymentRefusal {
        if ($amount === null) {
            return $allowOmitted ? null : PaymentRefusal::invalid('amount', 'amount_invalid', 'An amount is required');
        }
        if (!is_finite($amount)) {
            return PaymentRefusal::invalid('amount', 'amount_invalid', 'The amount must be a positive number');
        }
        $rounded = $payment->roundToMinorUnit($amount);
        if ($rounded <= 0.0) {
            return PaymentRefusal::invalid('amount', 'amount_invalid', 'The amount must be a positive number');
        }

        return Money::greaterThan($rounded, $limit) ? PaymentRefusal::invalid('amount', $exceedsCode, $exceedsMessage) : null;
    }
}
