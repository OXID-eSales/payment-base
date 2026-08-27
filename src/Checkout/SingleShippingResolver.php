<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout;

use OxidEsales\PaymentBase\Checkout\Contract\SingleShippingResolverInterface;

/**
 * Sprint 07 — the single-shipping rule.
 *
 * A shop that offers one delivery set should not make its customers confirm
 * that carrier. This class decides when that shortcut is allowed; it holds no
 * state, touches no shop API, and knows no provider.
 *
 * Simpler than its payment sibling by one rule each: there is no `oxempty`
 * placeholder among delivery sets (an undeliverable basket yields an empty
 * list, which core already hides), and a delivery set never collects customer
 * input. What is left is the premise itself.
 */
final class SingleShippingResolver implements SingleShippingResolverInterface
{
    public function resolve(array $candidates): ?string
    {
        if (count($candidates) !== 1) {
            return null;
        }

        $candidate = current($candidates);
        if ($candidate === false) {
            return null;
        }

        // An empty id names a set that does not exist. Assigning it would write
        // a falsy sShipSet — the very state this feature exists to prevent.
        return $candidate->getId() === '' ? null : $candidate->getId();
    }
}
