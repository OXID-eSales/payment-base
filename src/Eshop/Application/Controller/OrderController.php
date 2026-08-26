<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Eshop\Application\Controller;

use OxidEsales\Eshop\Application\Model\PaymentList;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentBase\Checkout\ResolvesSinglePaymentMethod;
use Throwable;

/**
 * Sprint 06 — the order page must not offer a choice that does not exist.
 *
 * Adds one view getter the module's order-page template asks before rendering
 * the "payment method" block: when the shop has exactly one usable method, the
 * heading, the method line and the pencil that leads back to the payment step
 * are all pointless, so they are left out.
 *
 * The answer is recomputed per request from the same payment list core's own
 * validation uses, rather than remembered in the session — so activating a
 * second payment method brings the block back immediately.
 */
// @phpstan-ignore-next-line (OrderController_parent is an OXID virtual class generated at activation)
class OrderController extends OrderController_parent
{
    use ResolvesSinglePaymentMethod;

    private const SESSION_SHIP_SET = 'sShipSet';

    private ?bool $singlePaymentAutoAssigned = null;

    /**
     * Template getter — true when the payment block should be left out because
     * the shop offers exactly one usable payment method.
     */
    public function isSinglePaymentAutoAssigned(): bool
    {
        if ($this->singlePaymentAutoAssigned === null) {
            $this->singlePaymentAutoAssigned = $this->computeSinglePaymentAutoAssigned();
        }

        return $this->singlePaymentAutoAssigned;
    }

    protected function computeSinglePaymentAutoAssigned(): bool
    {
        if (!$this->getSinglePaymentSettings()->isAutoAssignEnabled()) {
            return false;
        }

        $selectedPaymentId = $this->readSelectedPaymentId();
        if ($selectedPaymentId === null) {
            return false;
        }

        return $this->resolveSinglePaymentIdFrom($this->readAvailablePaymentList()) === $selectedPaymentId;
    }

    /**
     * The payment the order is about to be placed with — the parent has already
     * loaded and validated it for the page.
     */
    protected function readSelectedPaymentId(): ?string
    {
        try {
            $payment = $this->getPayment();
            if ($payment === false || $payment === null) {
                return null;
            }
            $paymentId = $payment->getId();
        } catch (Throwable) {
            return null;
        }

        return is_string($paymentId) && $paymentId !== '' ? $paymentId : null;
    }

    /**
     * The methods this basket, user and delivery set actually allow — the very
     * list Payment::isValidPayment() checks against, so "one method" here means
     * the same thing it means to the shop.
     *
     * @return array<array-key, mixed>
     */
    protected function readAvailablePaymentList(): array
    {
        try {
            $basket = $this->getBasket();
            if ($basket === false || $basket === null) {
                return [];
            }

            $paymentList = Registry::get(PaymentList::class)->getPaymentList(
                Registry::getSession()->getVariable(self::SESSION_SHIP_SET),
                $basket->getPriceForPayment(),
                $this->getUser()
            );
        } catch (Throwable) {
            return [];
        }

        return is_array($paymentList) ? $paymentList : [];
    }
}
