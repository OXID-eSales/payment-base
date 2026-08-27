<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentBase\Checkout\Contract\PaymentStepSkipGuardInterface;
use Throwable;

/**
 * Sprint 07 S6 — the one thing that makes skipping the payment step safe.
 *
 * `OrderController::render()` redirects to `cl=payment` whenever `getPayment()`
 * comes back false, and a skipping payment step redirects to `cl=order`. The
 * two validate with identical inputs — the assigner deliberately mirrors what
 * the order step will ask — so in practice they agree. In practice is not good
 * enough for a pair of 302s pointing at each other: a disagreement would spend
 * the customer's checkout in a redirect loop.
 *
 * So the skip is a one-shot. It is granted, taken, and not granted again until
 * the order step has actually rendered. A bounce therefore costs one extra
 * redirect and then shows the payment step — reduced to its bare form, but with
 * a working "next" button, which is exactly the pre-S6 behaviour.
 *
 * Every failure mode resolves to "do not skip": refusing the shortcut costs a
 * click, taking it wrongly could strand the customer.
 */
class PaymentStepSkipGuard implements PaymentStepSkipGuardInterface
{
    private const SESSION_KEY = 'oepbPaymentStepSkipped';

    public function maySkip(): bool
    {
        try {
            $session = $this->getSession();

            return $session !== null && $session->getVariable(self::SESSION_KEY) !== true;
        } catch (Throwable) {
            return false;
        }
    }

    public function markSkipped(): void
    {
        try {
            $this->getSession()?->setVariable(self::SESSION_KEY, true);
        } catch (Throwable) {
            // Unable to record the skip. maySkip() answers false on the same
            // failure, so the shortcut is simply not taken.
        }
    }

    public function clear(): void
    {
        try {
            $this->getSession()?->deleteVariable(self::SESSION_KEY);
        } catch (Throwable) {
            // Leaving the flag set only costs the next visit its shortcut.
        }
    }

    protected function getSession(): mixed
    {
        return Registry::getSession();
    }
}
