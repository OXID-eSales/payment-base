<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\DataType;

use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminStateHelp;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminStateHelpRow;
use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Annotations\Type;

/**
 * `adminPaymentContractStateHelp`: what each contract state means and, when the provider contributes, its own status.
 * `providerLabel` is null when no provider column exists (none asked for, or none registered).
 *
 * @since 1.7.0
 */
#[Type(name: 'AdminPaymentStateHelp')]
final class AdminPaymentStateHelp
{
    public function __construct(private readonly AdminStateHelp $help)
    {
    }

    #[Field]
    public function providerLabel(): ?string
    {
        return $this->help->providerLabel;
    }

    /**
     * @return AdminPaymentStateHelpRow[]
     */
    #[Field]
    public function rows(): array
    {
        return array_map(
            static fn (AdminStateHelpRow $row): AdminPaymentStateHelpRow => new AdminPaymentStateHelpRow($row),
            $this->help->rows,
        );
    }
}
