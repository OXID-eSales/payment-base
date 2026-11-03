<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter;

/**
 * Where the shop owner is reached, and who mail to them comes from.
 *
 * @since 1.8.0
 */
final readonly class ShopOwnerAddress
{
    public function __construct(
        public string $address,
        public string $name,
        public string $fromAddress,
    ) {
    }

    public function isReachable(): bool
    {
        return $this->address !== '' && $this->fromAddress !== '';
    }
}
