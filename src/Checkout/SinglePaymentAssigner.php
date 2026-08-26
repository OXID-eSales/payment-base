<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout;

use OxidEsales\Eshop\Application\Model\Payment;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentBase\Checkout\Contract\SinglePaymentAssignerInterface;
use Throwable;

/**
 * Sprint 06 — writes the auto-assigned payment method into the checkout.
 *
 * Every write mirrors what core's PaymentController::validatePayment() does on
 * success, and the validation itself goes through the payment's own
 * isValidPayment(): auto-assignment is a shortcut around the *click*, never
 * around the *rules*.
 *
 * The shop is reached through three protected seams so the write sequence is
 * unit-testable without a bootstrap.
 */
class SinglePaymentAssigner implements SinglePaymentAssignerInterface
{
    private const SESSION_PAYMENT_ID = 'paymentid';
    private const SESSION_DYN_VALUE = 'dynvalue';
    private const SESSION_SHIP_SET = 'sShipSet';
    private const SESSION_SELECTED_PAYMENT_ID = '_selected_paymentid';

    public function assign(string $paymentId, mixed $user): bool
    {
        if ($paymentId === '' || $user === null) {
            return false;
        }

        try {
            return $this->assignValidated($paymentId, $user);
        } catch (Throwable) {
            return false;
        }
    }

    private function assignValidated(string $paymentId, mixed $user): bool
    {
        $payment = $this->loadPayment($paymentId);
        $session = $this->getSession();
        if ($payment === null || $session === null) {
            return false;
        }

        $basket = $session->getBasket();
        if ($basket === null) {
            return false;
        }

        // The effective delivery set — parent::render() has already resolved it
        // and Basket::setShipping() mirrored it into the session. The order step
        // validates against this very value, so we must use it too.
        $shipSetId = $session->getVariable(self::SESSION_SHIP_SET);

        $basket->setPayment(null);

        if ($payment->isValidPayment([], $this->getShopId(), $user, $basket->getPriceForPayment(), $shipSetId) !== true) {
            return false;
        }

        $session->setVariable(self::SESSION_PAYMENT_ID, $paymentId);
        $session->setVariable(self::SESSION_DYN_VALUE, []);
        $basket->setShipping($shipSetId);
        $session->deleteVariable(self::SESSION_SELECTED_PAYMENT_ID);

        return true;
    }

    protected function loadPayment(string $paymentId): mixed
    {
        /** @var mixed $payment */
        $payment = oxNew(Payment::class);

        return $payment->load($paymentId) ? $payment : null;
    }

    protected function getSession(): mixed
    {
        return Registry::getSession();
    }

    protected function getShopId(): mixed
    {
        return Registry::getConfig()->getShopId();
    }
}
