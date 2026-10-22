<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Support;

use OxidEsales\PaymentBase\Checkout\Headless\GuestDetails;
use TheCodingMachine\GraphQLite\Annotations\Mutation;

/**
 * Stands in for a provider's `<provider>CheckoutStart`: a mutation taking
 * `guest: GuestCheckoutInput`, so the shared input types appear in a schema
 * and their argument resolution can be run. Only loaded by tests that first
 * check GraphQLite is installed (the attribute is GraphQLite's).
 */
final class GuestInputProbe
{
    public ?GuestDetails $received = null;

    #[Mutation]
    public function guestProbe(?GuestDetails $guest = null): bool
    {
        $this->received = $guest;

        return $guest !== null;
    }
}
