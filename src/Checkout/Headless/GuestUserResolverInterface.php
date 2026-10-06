<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Headless;

use InvalidArgumentException;

/**
 * The shop user a buyer an agent describes becomes: the existing account for
 * the e-mail, or a guest account with the given address.
 *
 * @since 3.0.0
 */
interface GuestUserResolverInterface
{
    /**
     * @param array<string, mixed> $buyer ACP `buyer`: email (required), first_name, last_name, phone_number
     * @param array<string, mixed>|null $address ACP `fulfillment_address`: name, line_one, line_two, city, state, country (ISO 3166-1 alpha-2), postal_code
     * @return string the user id
     * @throws InvalidArgumentException without an e-mail, or with a country the shop does not know
     */
    public function resolve(array $buyer, ?array $address): string;
}
