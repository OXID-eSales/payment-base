<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentBase\Checkout\Contract\SingleShippingAssignerInterface;
use Throwable;

/**
 * Sprint 07 — writes the auto-assigned delivery set into the checkout.
 *
 * Every write mirrors core's own `PaymentController::changeshipping()`, so no
 * downstream code can tell the difference between an auto-assignment and a
 * customer picking the only option in the dropdown.
 *
 * **Usually there is nothing to do.** `PaymentController::getPaymentList()`
 * resolves the active set during `parent::render()` and calls
 * `Basket::setShipping()`, which mirrors the id into the session — so by the
 * time we are asked, `sShipSet` normally already holds exactly the set we would
 * assign. Re-writing it would force a basket recalculation (`onUpdate()`) for
 * no change at all, on every render of a single-carrier shop.
 *
 * So the assigner corrects rather than writes: it makes this module's decision
 * authoritative if the session ever disagrees — another module in the
 * PaymentController chain may suppress or alter core's write — and otherwise
 * gets out of the way. That is what makes hiding the selector safe: the value
 * the hidden `<select>` would have submitted is guaranteed to be there.
 *
 * The shop is reached through one protected seam so the write sequence is
 * unit-testable without a bootstrap.
 */
class SingleShippingAssigner implements SingleShippingAssignerInterface
{
    private const SESSION_SHIP_SET = 'sShipSet';

    public function assign(string $shipSetId): bool
    {
        if ($shipSetId === '') {
            return false;
        }

        try {
            return $this->assignToBasket($shipSetId);
        } catch (Throwable) {
            return false;
        }
    }

    private function assignToBasket(string $shipSetId): bool
    {
        $session = $this->getSession();
        if ($session === null) {
            return false;
        }

        $basket = $session->getBasket();
        if ($basket === null) {
            return false;
        }

        // The common case: core already resolved and persisted this very set
        // during parent::render(). Nothing to correct, and no reason to make
        // the basket recalculate.
        if ($session->getVariable(self::SESSION_SHIP_SET) === $shipSetId) {
            return true;
        }

        // Core's changeshipping() sequence, in core's order: clear, mark the
        // basket for recalculation, then record the choice. The basket picks
        // the new id up lazily through getShippingId().
        $basket->setShipping(null);
        $basket->onUpdate();
        $session->setVariable(self::SESSION_SHIP_SET, $shipSetId);

        return true;
    }

    protected function getSession(): mixed
    {
        return Registry::getSession();
    }
}
