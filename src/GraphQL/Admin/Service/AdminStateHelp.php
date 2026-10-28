<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

/**
 * The contract-state Help table: rows in ladder order and, when a provider contributes, the label of its column.
 *
 * @since 1.7.0
 */
final readonly class AdminStateHelp
{
    /**
     * @param list<AdminStateHelpRow> $rows
     */
    public function __construct(public ?string $providerLabel, public array $rows)
    {
    }
}
