<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Controller;

use OxidEsales\Eshop\Core\Registry;

/**
 * The shop session as the return leg's session writer. Each provider module
 * ships an identical class and binds the interface to it; payment-base now
 * binds it too (Sprint 15 / S6) so CheckoutReturnResponder can be wired here
 * for the headless return. Whichever definition wins, the behaviour is one.
 *
 * @since 3.0.0
 */
final class OxidSessionWriter implements SessionWriterInterface
{
    public function writeSessChallenge(string $orderId): void
    {
        Registry::getSession()->setVariable('sess_challenge', $orderId);
    }
}
