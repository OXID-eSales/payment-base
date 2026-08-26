<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Eshop\Application\Controller;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\PaymentBase\Checkout\Contract\SinglePaymentAssignerInterface;
use OxidEsales\PaymentBase\Checkout\ResolvesSinglePaymentMethod;

/**
 * Sprint 06 — nothing to choose, nothing to show.
 *
 * When the shop offers exactly one usable payment method, this extension
 * assigns it while the payment step renders and tells the template to leave the
 * payment-selection block out. The customer sees the step's *other* content —
 * the delivery-set selector lives on the same page — and just continues.
 *
 * The step is deliberately **not** skipped: it also carries the shipping
 * choice, and its "next" button is what submits the checkout forward.
 *
 * The decision runs after parent::render(), because the parent is what resolves
 * the delivery set and the payment list the decision reads.
 */
// @phpstan-ignore-next-line (PaymentController_parent is an OXID virtual class generated at activation)
class PaymentController extends PaymentController_parent
{
    use ResolvesSinglePaymentMethod;

    private ?string $autoAssignedPaymentId = null;

    public function render(): string
    {
        $template = (string) parent::render();

        $this->autoAssignedPaymentId = $this->assignSinglePaymentMethod();

        return $template;
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
