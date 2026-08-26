<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout;

use OxidEsales\PaymentBase\Checkout\Contract\SinglePaymentResolverInterface;

/**
 * Sprint 06 — the single-payment rule.
 *
 * A shop that offers one payment method should not make its customers confirm
 * that method. This class decides when that shortcut is allowed; it holds no
 * state, touches no shop API, and knows no provider.
 */
final class SinglePaymentResolver implements SinglePaymentResolverInterface
{
    /**
     * OXID's placeholder for "no payment possible" in blOtherCountryOrder
     * shops. It appears in payment lists but is not a method anyone can pay
     * with, so it can never be the auto-assigned one.
     */
    public const EMPTY_PAYMENT_ID = 'oxempty';

    public function resolve(array $candidates): ?string
    {
        if (count($candidates) !== 1) {
            return null;
        }

        $candidate = current($candidates);
        if ($candidate === false) {
            return null;
        }

        if ($candidate->getId() === '' || $candidate->getId() === self::EMPTY_PAYMENT_ID) {
            return null;
        }

        if ($candidate->requiresUserInput()) {
            return null;
        }

        return $candidate->getId();
    }
}
