<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\DataType;

use DateTimeImmutable;
use OxidEsales\PaymentBase\AtRisk\PaymentAtRisk;
use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Annotations\Type;

/**
 * One open "payment at risk" flag of an order: why (REASON in the lifecycle's upper-case spelling), the
 * provider's reference behind it, and when. Ids only; the merchant decides in the payment panel.
 *
 * @since 1.8.0
 */
#[Type(name: 'AdminPaymentAtRisk')]
final class AdminPaymentAtRisk
{
    public function __construct(private readonly PaymentAtRisk $flag)
    {
    }

    #[Field(outputType: 'ID!')]
    public function contractId(): string
    {
        return $this->flag->contractId;
    }

    #[Field(outputType: 'ID')]
    public function orderId(): ?string
    {
        return $this->flag->orderId;
    }

    #[Field]
    public function reason(): string
    {
        return strtoupper($this->flag->reason->value);
    }

    #[Field]
    public function reference(): string
    {
        return $this->flag->reference;
    }

    #[Field]
    public function raisedAt(): DateTimeImmutable
    {
        return $this->flag->raisedAt;
    }
}
