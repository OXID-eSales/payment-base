<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Eshop\Application\Controller;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\PaymentBase\Checkout\Contract\SinglePaymentAssignerInterface;
use OxidEsales\PaymentBase\Checkout\Contract\SingleShippingAssignerInterface;
use OxidEsales\PaymentBase\Checkout\ResolvesSinglePaymentMethod;
use OxidEsales\PaymentBase\Checkout\ResolvesSingleShippingMethod;

/**
 * Sprint 06 — nothing to choose, nothing to show.
 *
 * When the shop offers exactly one usable payment method, this extension
 * assigns it while the payment step renders and tells the template to leave the
 * payment-selection block out. The customer sees the step's *other* content —
 * the delivery-set selector lives on the same page — and just continues.
 *
 * The step is deliberately **not** skipped: its "next" button is what submits
 * the checkout forward.
 *
 * Sprint 07 does the same for the delivery-set selector that shares this step,
 * and adds the write core omits: `sShipSet` is persisted on the customer's
 * behalf, because once the selector is hidden nothing else on the page can
 * write it.
 *
 * The decision runs after parent::render(), because the parent is what resolves
 * the delivery set and the payment list the decision reads.
 */
// @phpstan-ignore-next-line (PaymentController_parent is an OXID virtual class generated at activation)
class PaymentController extends PaymentController_parent
{
    use ResolvesSinglePaymentMethod;
    use ResolvesSingleShippingMethod;

    private ?string $autoAssignedPaymentId = null;
    private ?string $autoAssignedShipSetId = null;

    public function render(): string
    {
        $template = (string) parent::render();

        // Shipping first, and the order is load-bearing rather than stylistic.
        // SinglePaymentAssigner validates the method against the session's
        // sShipSet, which core writes only in changeshipping() — so on a first
        // visit the variable does not exist yet. Assigning shipping second
        // would validate the payment against a falsy delivery set on exactly
        // the request that matters.
        $this->autoAssignedShipSetId = $this->assignSingleShippingMethod();
        $this->autoAssignedPaymentId = $this->assignSinglePaymentMethod();

        return $template;
    }

    /**
     * Template getter — true when the delivery-set selector should be left out
     * because the shop offers exactly one set and it has been assigned already.
     */
    public function isSingleShippingAutoAssigned(): bool
    {
        return $this->autoAssignedShipSetId !== null;
    }

    /**
     * Template getter — the assigned delivery-set id.
     */
    public function getSingleShippingId(): string
    {
        return $this->autoAssignedShipSetId ?? '';
    }

    /**
     * @return string|null the assigned delivery-set id, or null when the
     *                     customer has to see the selector after all
     */
    protected function assignSingleShippingMethod(): ?string
    {
        if (!$this->getSingleShippingSettings()->isAutoAssignEnabled()) {
            return null;
        }

        // Deliberately no payment-error guard, unlike the payment half. A
        // payment error is about the method, not the carrier; with one set the
        // selector offers nothing either way, and the sShipSet write is what
        // lets the customer's retry validate at all.
        $shipSetId = $this->resolveSingleShipSetId();
        if ($shipSetId === null) {
            return null;
        }

        return $this->getSingleShippingAssigner()->assign($shipSetId) ? $shipSetId : null;
    }

    /**
     * The sets core has already filtered for this user, country and basket.
     * Only populated once parent::render() has run — core memoises it together
     * with the payment list.
     */
    protected function resolveSingleShipSetId(): ?string
    {
        $deliverySetList = $this->getAllSets();
        if (!is_array($deliverySetList)) {
            return null;
        }

        return $this->resolveSingleShipSetIdFrom($deliverySetList);
    }

    protected function getSingleShippingAssigner(): SingleShippingAssignerInterface
    {
        /** @var SingleShippingAssignerInterface $assigner */
        $assigner = ContainerFactory::getInstance()
            ->getContainer()
            ->get(SingleShippingAssignerInterface::class);

        return $assigner;
    }

    /**
     * Template getter — true when the payment-selection block should be left
     * out because the shop offers exactly one usable method and it has been
     * assigned already.
     */
    public function isSinglePaymentAutoAssigned(): bool
    {
        return $this->autoAssignedPaymentId !== null;
    }

    /**
     * Template getter — the assigned payment id, so the reduced form can still
     * post it and not rely on the session alone.
     */
    public function getSinglePaymentId(): string
    {
        return $this->autoAssignedPaymentId ?? '';
    }

    /**
     * @return string|null the assigned payment id, or null when the customer
     *                     has to see the selection after all
     */
    protected function assignSinglePaymentMethod(): ?string
    {
        if (!$this->getSinglePaymentSettings()->isAutoAssignEnabled()) {
            return null;
        }

        // A pending payment error is a message for the customer. Hiding the
        // selection they would have to correct leaves them stuck on it.
        if ((bool) $this->getPaymentError()) {
            return null;
        }

        $paymentId = $this->resolveSinglePaymentId();
        if ($paymentId === null) {
            return null;
        }

        return $this->getSinglePaymentAssigner()->assign($paymentId, $this->getUser())
            ? $paymentId
            : null;
    }

    protected function resolveSinglePaymentId(): ?string
    {
        $paymentList = $this->getPaymentList();
        if (!is_array($paymentList)) {
            return null;
        }

        return $this->resolveSinglePaymentIdFrom($paymentList);
    }

    protected function getSinglePaymentAssigner(): SinglePaymentAssignerInterface
    {
        /** @var SinglePaymentAssignerInterface $assigner */
        $assigner = ContainerFactory::getInstance()
            ->getContainer()
            ->get(SinglePaymentAssignerInterface::class);

        return $assigner;
    }
}
