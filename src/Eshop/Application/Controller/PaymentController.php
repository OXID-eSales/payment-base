<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Eshop\Application\Controller;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentBase\Checkout\Contract\PaymentStepSkipGuardInterface;
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
 * Sprint 07 S6 (decision D1) skips the step outright, but *only* when both
 * halves were assigned — at which point the page has nothing on it but a
 * heading, a "previous" link and a "next" button. With either half still a real
 * choice the step renders, as it did before.
 *
 * Sprint 07 does the same for the delivery-set selector that shares this step,
 * and makes sure `sShipSet` really names the only available set before hiding
 * the control that would otherwise submit it.
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

        // Shipping first, because SinglePaymentAssigner validates the method
        // against the session's sShipSet. Normally core has already put the
        // right value there (getPaymentList() -> Basket::setShipping()), so the
        // order makes no difference — but on the one request where the shipping
        // assignment has something to correct, running it second would validate
        // the payment against the value it is about to replace.
        $this->autoAssignedShipSetId = $this->assignSingleShippingMethod();
        $this->autoAssignedPaymentId = $this->assignSinglePaymentMethod();

        $this->skipStepIfNothingIsLeftToChoose();

        return $template;
    }

    /**
     * Sprint 07 S6 — forward to the order step when this page has nothing left
     * on it.
     *
     * The condition is deliberately "both were assigned", not "both settings
     * are on": an assignment that was refused (an invalid method, a pending
     * payment error) leaves a real page behind, and skipping past it would hide
     * something the customer has to act on.
     *
     * Nothing has been written to the output yet — `parent::render()` returns a
     * template *name*, the shop renders it afterwards — so redirecting here is
     * safe.
     */
    protected function skipStepIfNothingIsLeftToChoose(): void
    {
        if ($this->autoAssignedShipSetId === null || $this->autoAssignedPaymentId === null) {
            return;
        }

        // One-shot. The order step redirects back here whenever it cannot
        // resolve a payment, and two 302s pointing at each other would spend
        // the customer's checkout in a loop. See PaymentStepSkipGuard.
        $guard = $this->getPaymentStepSkipGuard();
        if (!$guard->maySkip()) {
            return;
        }

        $guard->markSkipped();
        $this->redirectToOrderStep();
    }

    protected function redirectToOrderStep(): void
    {
        // Secure home URL, not getShopCurrentURL(): the checkout must stay on
        // SSL, and the current URL would drag this request's own parameters
        // (fnc, sShipSet, …) along to the order step.
        Registry::getUtils()->redirect(
            Registry::getConfig()->getShopSecureHomeUrl() . 'cl=order',
            false,
            302
        );
    }

    protected function getPaymentStepSkipGuard(): PaymentStepSkipGuardInterface
    {
        /** @var PaymentStepSkipGuardInterface $guard */
        $guard = ContainerFactory::getInstance()
            ->getContainer()
            ->get(PaymentStepSkipGuardInterface::class);

        return $guard;
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
     * with the payment list, and reading it earlier returns an empty list.
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
