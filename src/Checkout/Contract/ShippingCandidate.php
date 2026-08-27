<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Contract;

/**
 * One delivery set, reduced to the single fact the single-shipping decision
 * needs: how it is identified.
 *
 * Its payment sibling carries a second fact — whether the method collects data
 * from the customer on the step we hide. A delivery set collects nothing: it
 * has no `oxdeliveryset__oxvaldesc`, renders no input fields, and the selector
 * is a `<select>` with one `<option>`. So there is nothing else to model here,
 * and a field added only for symmetry would be a field nobody reads.
 *
 * Deliberately provider-agnostic and framework-free — shipping is not a payment
 * concern, and a core delivery set becomes a candidate exactly like a
 * module-provided one.
 */
final class ShippingCandidate
{
    public function __construct(
        private readonly string $id,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }
}
