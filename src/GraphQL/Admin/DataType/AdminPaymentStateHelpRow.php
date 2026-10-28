<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\DataType;

use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminStateHelpRow;
use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Annotations\Type;

/**
 * One state of the Help table. `state` is the lifecycle spelling of the row's first state; `states` lists every
 * state the row covers (COMMITTED and FULFILLED share a row). `providerStatus` is null where the provider names none.
 *
 * @since 1.7.0
 */
#[Type(name: 'AdminPaymentStateHelpRow')]
final class AdminPaymentStateHelpRow
{
    public function __construct(private readonly AdminStateHelpRow $row)
    {
    }

    #[Field]
    public function state(): string
    {
        return $this->row->state;
    }

    /**
     * @return string[]
     */
    #[Field]
    public function states(): array
    {
        return $this->row->states;
    }

    #[Field]
    public function meaning(): string
    {
        return $this->row->meaning;
    }

    #[Field]
    public function providerStatus(): ?string
    {
        return $this->row->providerStatus;
    }
}
