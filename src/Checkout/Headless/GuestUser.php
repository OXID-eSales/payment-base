<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Headless;

/**
 * The shop user a guest checkout runs as.
 *
 * @param string|null $deliveryAddressId the `oxaddress` row created for a delivery address that differs from the
 *        invoice address, null without one
 *
 * @since 1.5.0
 */
final readonly class GuestUser
{
    public function __construct(
        public string $userId,
        public ?string $deliveryAddressId = null,
    ) {
    }
}
