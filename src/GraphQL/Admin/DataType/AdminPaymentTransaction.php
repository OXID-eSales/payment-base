<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\DataType;

use DateTimeImmutable;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentTransactionDetails;
use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Annotations\Type;

/**
 * One transaction of the payment (authorisation, capture, refund ...): ids, amount and status. The provider's
 * own payload is never exposed; provider plugins add their read-only details with their own type extension.
 *
 * @since 1.5.0
 */
#[Type(name: 'AdminPaymentTransaction')]
final class AdminPaymentTransaction
{
    public function __construct(private readonly PaymentTransactionDetails $transaction)
    {
    }

    #[Field(outputType: 'ID!')]
    public function id(): string
    {
        return $this->transaction->id;
    }

    #[Field]
    public function type(): string
    {
        return $this->transaction->type;
    }

    #[Field]
    public function status(): string
    {
        return $this->transaction->status;
    }

    #[Field]
    public function amount(): float
    {
        return $this->transaction->amount;
    }

    #[Field]
    public function createdAt(): DateTimeImmutable
    {
        return $this->transaction->createdAt;
    }

    /**
     * The provider's id of the transaction (a capture id, a refund id); not a secret.
     */
    #[Field]
    public function providerReference(): ?string
    {
        return $this->transaction->providerReference;
    }
}
