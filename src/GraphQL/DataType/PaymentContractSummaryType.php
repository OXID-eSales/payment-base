<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\DataType;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Contract\PaymentContractSummary;
use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Annotations\Type;

/**
 * `Order.paymentContract`: where the payment behind an order stands.
 * The schema name drops the suffix, which only keeps this wrapper apart from
 * the domain value object it exposes.
 *
 * @since 1.4.0
 */
#[Type(name: 'PaymentContractSummary')]
final class PaymentContractSummaryType
{
    public function __construct(private readonly PaymentContractSummary $summary)
    {
    }

    #[Field]
    public function contractId(): string
    {
        return $this->summary->contractId;
    }

    #[Field]
    public function state(): string
    {
        return $this->summary->state;
    }

    #[Field]
    public function provider(): string
    {
        return $this->summary->provider;
    }

    #[Field]
    public function updatedAt(): DateTimeImmutable
    {
        return $this->summary->updatedAt;
    }
}
