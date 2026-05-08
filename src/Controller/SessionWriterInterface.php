<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Controller;

/**
 * Tiny seam used by {@see CheckoutReturnResponder} to write
 * `sess_challenge` so OXID's thankyou controller can load the order.
 *
 * payment-base is framework-neutral — the concrete implementation
 * that calls `Registry::getSession()->setVariable(...)` lives inside
 * each PSP module that consumes the responder.
 */
interface SessionWriterInterface
{
    public function writeSessChallenge(string $orderId): void;
}
