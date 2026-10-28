<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

/**
 * One row of the state Help as the admin API shows it.
 *
 * @since 1.7.0
 */
final readonly class AdminStateHelpRow
{
    /**
     * @param list<string> $states every contract state the row stands for (committed and fulfilled share one)
     */
    public function __construct(
        public string $state,
        public array $states,
        public string $meaning,
        public ?string $providerStatus,
    ) {
    }
}
