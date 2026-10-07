<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Headless;

use InvalidArgumentException;

/**
 * Persist the basket an agent describes as items (ACP `create_checkout`) as
 * the `oxuserbaskets` row the GraphQL Storefront would have persisted,
 * payment included - so the rest of the headless path is one path.
 *
 * @since 3.0.0
 */
interface UserBasketFactoryInterface
{
    /**
     * @param list<array{id?: string, quantity?: int|float}> $items
     * @return string the new basket id
     * @throws InvalidArgumentException on empty or malformed items
     */
    public function create(string $userId, array $items, string $paymentId): string;
}
