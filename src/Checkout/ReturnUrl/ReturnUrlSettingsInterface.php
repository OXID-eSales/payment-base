<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\ReturnUrl;

/**
 * The storefront origins the merchant allows as return targets, beyond the
 * shop itself.
 *
 * @since 3.0.0
 */
interface ReturnUrlSettingsInterface
{
    /**
     * @return list<string> origins or URLs as the merchant typed them; empty = shop only
     */
    public function getAllowedOrigins(): array;
}
