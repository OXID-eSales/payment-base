<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\DataType;

use OxidEsales\PaymentBase\Admin\Contract\AdminActionOptions;
use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Annotations\Type;

/**
 * `AdminPaymentContract.actionOptions`: what the provider accepts as free text. An empty reason list means free
 * text (up to `reasonMaxLength` characters); a non-empty one is the only set the mutation accepts
 * (`reason_invalid` otherwise). `refundDescription` tells whether the refund form should offer a description.
 *
 * @since 1.7.0
 */
#[Type(name: 'AdminPaymentActionOptions')]
final class AdminPaymentActionOptions
{
    public function __construct(private readonly AdminActionOptions $options)
    {
    }

    /**
     * @return string[]
     */
    #[Field]
    public function refundReasons(): array
    {
        return $this->options->refundReasons;
    }

    /**
     * @return string[]
     */
    #[Field]
    public function voidReasons(): array
    {
        return $this->options->voidReasons;
    }

    #[Field]
    public function refundDescription(): bool
    {
        return $this->options->refundDescription;
    }

    #[Field]
    public function reasonMaxLength(): int
    {
        return $this->options->reasonMaxLength;
    }
}
