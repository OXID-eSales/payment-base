<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation\User;

/**
 * Decouples the validator from the OXID User/Address models so it runs without a booted shop.
 */
interface UserFieldReaderInterface
{
    /** Billing value of a logical field; empty string when unknown or unset. */
    public function readBillingField(string $logicalName): string;

    public function hasDeliveryAddress(): bool;

    /** Delivery value of a logical field; empty string when unknown or unset. */
    public function readDeliveryField(string $logicalName): string;
}
