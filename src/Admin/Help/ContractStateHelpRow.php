<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Admin\Help;

/**
 * One row of the shared contract-state Help table (Sprint 14, MOL-10): the state(s) shown in the
 * first cell and the translation ident of the meaning. Providers attach their own status by
 * {@see key()} - the first state of the row.
 */
final class ContractStateHelpRow
{
    /**
     * @param list<string> $states
     */
    public function __construct(
        public readonly array $states,
        public readonly string $meaningIdent,
    ) {
    }

    /** The key under which a provider lists its status for this row. */
    public function key(): string
    {
        return $this->states[0];
    }
}
