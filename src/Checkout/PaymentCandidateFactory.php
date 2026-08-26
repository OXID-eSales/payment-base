<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout;

use OxidEsales\PaymentBase\Checkout\Contract\PaymentCandidate;
use Throwable;

/**
 * Turns OXID's payment list into candidates the resolver can reason about.
 *
 * This is the only place that touches the shop's Payment models, which is why
 * every question asked of them is wrapped: the list may legitimately contain a
 * foreign module's model that answers differently, and a checkout must never
 * break over a payment it merely failed to interrogate.
 *
 * Stateless and deterministic, hence static (see the module's static-utility
 * convention) — there is nothing here worth injecting.
 */
final class PaymentCandidateFactory
{
    /**
     * @param array<array-key, mixed> $paymentList payment id => OXID Payment model
     * @return list<PaymentCandidate>
     */
    public static function fromPaymentList(array $paymentList): array
    {
        $candidates = [];

        foreach ($paymentList as $key => $payment) {
            $id = self::resolveId($key, $payment);
            if ($id === null) {
                continue;
            }

            $candidates[] = new PaymentCandidate($id, self::requiresUserInput($payment));
        }

        return $candidates;
    }

    /**
     * The array key is the payment id in every core list. A numerically indexed
     * array carries no id, so the model is asked for its own — an id of "0"
     * would name a method that does not exist.
     */
    private static function resolveId(int|string $key, mixed $payment): ?string
    {
        if (is_string($key) && $key !== '') {
            return $key;
        }

        $id = self::readModelId($payment);

        return $id === '' ? null : $id;
    }

    private static function readModelId(mixed $payment): string
    {
        try {
            $id = $payment->getId();
        } catch (Throwable) {
            return '';
        }

        return is_string($id) ? $id : '';
    }

    /**
     * OXID exposes the payment step's input fields as the payment's dynamic
     * values (parsed from oxpayments.OXVALDESC). No dynamic values means
     * nothing on that page belongs to the customer.
     */
    private static function requiresUserInput(mixed $payment): bool
    {
        try {
            $dynValues = $payment->getDynValues();
        } catch (Throwable) {
            return false;
        }

        return is_array($dynValues) && $dynValues !== [];
    }
}
