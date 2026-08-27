<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Eshop\Application\Controller;

use OxidEsales\Eshop\Application\Model\DeliverySetList;
use OxidEsales\Eshop\Application\Model\PaymentList;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentBase\Checkout\ResolvesSinglePaymentMethod;
use OxidEsales\PaymentBase\Checkout\ResolvesSingleShippingMethod;
use Throwable;

/**
 * Sprint 06 — the order page must not offer a choice that does not exist.
 *
 * Adds the view getters the module's order-page template asks before rendering
 * the "payment method" block (sprint 06) and the "shipping carrier" block
 * (sprint 07): when the shop has exactly one usable method or exactly one
 * delivery set, the heading, the value line and the pencil that leads back to
 * the payment step are all pointless, so they are left out.
 *
 * Both answers are recomputed per request from the same lists core's own
 * validation uses, rather than remembered in the session — so activating a
 * second payment method or delivery set brings the block back immediately.
 *
 * The two decisions are independent: hiding one block never implies the other.
 */
// @phpstan-ignore-next-line (OrderController_parent is an OXID virtual class generated at activation)
class OrderController extends OrderController_parent
{
    use ResolvesSinglePaymentMethod;
    use ResolvesSingleShippingMethod;

    private const SESSION_SHIP_SET = 'sShipSet';

    private ?bool $singlePaymentAutoAssigned = null;
    private ?bool $singleShippingAutoAssigned = null;

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

    /**
     * Template getter — true when the shipping-carrier block should be left out
     * because the shop offers exactly one delivery set.
     */
    public function isSingleShippingAutoAssigned(): bool
    {
        if ($this->singleShippingAutoAssigned === null) {
            $this->singleShippingAutoAssigned = $this->computeSingleShippingAutoAssigned();
        }

        return $this->singleShippingAutoAssigned;
    }

    protected function computeSingleShippingAutoAssigned(): bool
    {
        if (!$this->getSingleShippingSettings()->isAutoAssignEnabled()) {
            return false;
        }

        $selectedShipSetId = $this->readSelectedShipSetId();
        if ($selectedShipSetId === null) {
            return false;
        }

        return $this->resolveSingleShipSetIdFrom($this->readAvailableDeliverySetList()) === $selectedShipSetId;
    }

    /**
     * The delivery set the order is about to be placed with — the parent has
     * already loaded it from the basket for the page.
     */
    protected function readSelectedShipSetId(): ?string
    {
        try {
            $shipSet = $this->getShipSet();
            if ($shipSet === false || $shipSet === null) {
                return null;
            }
            $shipSetId = $shipSet->getId();
        } catch (Throwable) {
            return null;
        }

        return is_string($shipSetId) && $shipSetId !== '' ? $shipSetId : null;
    }

    /**
     * The delivery sets this basket, user and country actually allow — the very
     * list the payment step offers, so "one carrier" here means the same thing
     * it means there.
     *
     * @return array<array-key, mixed>
     */
    protected function readAvailableDeliverySetList(): array
    {
        try {
            $basket = $this->getBasket();
            if ($basket === false || $basket === null) {
                return [];
            }

            // getDeliverySetData() answers [$allSets, $activeSetId, $paymentList],
            // and returns null outright when there is no user. Only the first
            // element is ours; destructuring it blind would trip over both.
            $deliverySetData = Registry::get(DeliverySetList::class)->getDeliverySetData(
                Registry::getSession()->getVariable(self::SESSION_SHIP_SET),
                $this->getUser(),
                $basket
            );
        } catch (Throwable) {
            return [];
        }

        if (!is_array($deliverySetData) || !isset($deliverySetData[0]) || !is_array($deliverySetData[0])) {
            return [];
        }

        return $deliverySetData[0];
    }
}
