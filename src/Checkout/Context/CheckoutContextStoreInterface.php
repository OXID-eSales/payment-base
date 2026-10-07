<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Context;

/**
 * Persistence for {@see PersistedCheckoutContext}: the whole flag set of one
 * scope, read and written as a unit.
 *
 * @since 3.0.0
 */
interface CheckoutContextStoreInterface
{
    /**
     * @return array<string, mixed> empty when the scope is unknown or expired
     */
    public function load(string $scopeId): array;

    /**
     * @param array<string, mixed> $data
     */
    public function save(string $scopeId, array $data, ?string $userId, ?string $basketId): void;
}
