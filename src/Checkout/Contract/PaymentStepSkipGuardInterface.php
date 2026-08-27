<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Contract;

/**
 * Permission to skip the payment step, granted at most once until the order
 * step has actually been reached.
 *
 * Exists because the two steps redirect at each other: the order step goes back
 * to `cl=payment` whenever it cannot resolve a payment, and a skipping payment
 * step goes forward to `cl=order`. Both sides validate with identical inputs and
 * should always agree — but a checkout must not depend on that.
 */
interface PaymentStepSkipGuardInterface
{
    /**
     * @return bool false once a skip has been taken and the order step has not
     *              yet rendered — i.e. we are back after a bounce
     */
    public function maySkip(): bool;

    public function markSkipped(): void;

    /**
     * Called when the order step renders, which re-arms the shortcut for a
     * later, legitimate return to the payment step.
     */
    public function clear(): void;
}
