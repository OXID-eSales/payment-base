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
 * This is the load-bearing half of the feature. Core does not persist the set
 * on a plain render — `getPaymentList()` calls `Basket::setShipping()` but
 * writes no session variable, and only `changeshipping()` writes `sShipSet`.
 * Meanwhile `validatePayment()` reads `sShipSet` from the request first and the
 * session second, and no form on the step posts it. Hiding the selector removes
 * the customer's last route to that value, so the assigner supplies it.
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

        // Core clears the shipping before setting it so the basket's cached
        // delivery cost is recomputed rather than carried over.
        $basket->setShipping(null);

        $session->setVariable(self::SESSION_SHIP_SET, $shipSetId);
        $basket->setShipping($shipSetId);

        return true;
    }

    protected function getSession(): mixed
    {
        return Registry::getSession();
    }
}
