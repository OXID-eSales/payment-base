<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\DataType;

use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCancelResult;
use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Annotations\Type;

/**
 * Result of `<provider>CheckoutCancel`.
 *
 * @since 3.0.0
 */
#[Type]
final class CheckoutCancelResult
{
    public function __construct(private readonly HeadlessCancelResult $result)
    {
    }

    #[Field]
    public function cancelled(): bool
    {
        return $this->result->cancelled;
    }

    #[Field]
    public function contractId(): string
    {
        return $this->result->contractId;
    }

    #[Field]
    public function contractState(): string
    {
        return $this->result->contractState;
    }
}
