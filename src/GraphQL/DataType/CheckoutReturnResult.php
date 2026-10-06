<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\DataType;

use OxidEsales\PaymentBase\Checkout\Headless\HeadlessReturnResult;
use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Annotations\Type;

/**
 * Result of `<provider>CheckoutReturn`.
 *
 * @since 3.0.0
 */
#[Type]
final class CheckoutReturnResult
{
    public function __construct(private readonly HeadlessReturnResult $result)
    {
    }

    /**
     * `committed` (the order is placed), `pending` (the PSP has not confirmed yet), `failed`.
     */
    #[Field]
    public function status(): string
    {
        return $this->result->status;
    }

    #[Field]
    public function orderId(): ?string
    {
        return $this->result->orderId;
    }

    #[Field]
    public function orderNumber(): ?string
    {
        return $this->result->orderNumber;
    }

    #[Field]
    public function contractState(): string
    {
        return $this->result->contractState;
    }
}
