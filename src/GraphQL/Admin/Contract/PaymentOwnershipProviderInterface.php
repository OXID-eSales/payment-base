<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Contract;

/**
 * The payment rows a provider module owns, so that the admin API can lock them (G09). payment-base knows no
 * provider: each provider module registers one implementation with the tag `oe.payment.ownership_provider`.
 *
 * @since 1.7.0
 */
interface PaymentOwnershipProviderInterface
{
    /**
     * The provider name the contracts record.
     */
    public function providerName(): string;

    /**
     * The ids of the payment rows the provider owns. One payment method per provider is the rule; a funding
     * source or other variant is never a row and is not listed.
     *
     * @return list<string>
     */
    public function paymentIds(): array;
}
