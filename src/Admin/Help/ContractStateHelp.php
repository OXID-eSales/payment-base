<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Admin\Help;

use OxidEsales\PaymentBase\Contract\ContractState;

/**
 * The shared "Help" table for the OXID contract states (Sprint 14, MOL-10): one row per state a
 * checkout can reach, in ladder order, each with a translated meaning. payment-base shows these two
 * columns on its own Settings tab; a provider module adds a third column with its own status and
 * shows the same table behind the "?" next to "OXID Contract Status" on its order panel.
 *
 * Built from the ContractState factories so a renamed state fails a test rather than a reader.
 * `draft` is not listed: it exists for a blink before the checkout-session event and never shows.
 */
final class ContractStateHelp
{
    /** Idents the table and the hint need besides the per-row meanings. */
    public const SHARED_IDENTS = [
        'PAYMENT_ADMIN_HELP',
        'PAYMENT_ADMIN_HELP_CONTRACT_STATES_INTRO',
        'PAYMENT_ADMIN_HELP_COL_CONTRACT_STATE',
        'PAYMENT_ADMIN_HELP_COL_MEANING',
        'PAYMENT_ADMIN_HELP_NONE',
        'PAYMENT_ADMIN_HELP_CLOSE',
    ];

    /**
     * @return list<ContractStateHelpRow>
     */
    public function rows(): array
    {
        return [
            $this->row([ContractState::notFinished()], 'PAYMENT_ADMIN_HELP_STATE_NOT_FINISHED'),
            $this->row([ContractState::pending()], 'PAYMENT_ADMIN_HELP_STATE_PENDING'),
            $this->row([ContractState::authorized()], 'PAYMENT_ADMIN_HELP_STATE_AUTHORIZED'),
            $this->row([ContractState::readyToCommit()], 'PAYMENT_ADMIN_HELP_STATE_READY_TO_COMMIT'),
            $this->row(
                [ContractState::committed(), ContractState::fulfilled()],
                'PAYMENT_ADMIN_HELP_STATE_COMMITTED_FULFILLED'
            ),
            $this->row([ContractState::cancelled()], 'PAYMENT_ADMIN_HELP_STATE_CANCELLED'),
            $this->row([ContractState::expired()], 'PAYMENT_ADMIN_HELP_STATE_EXPIRED'),
            $this->row([ContractState::failed()], 'PAYMENT_ADMIN_HELP_STATE_FAILED'),
        ];
    }

    /**
     * @param list<ContractState> $states
     */
    private function row(array $states, string $meaningIdent): ContractStateHelpRow
    {
        return new ContractStateHelpRow(
            array_map(static fn (ContractState $state): string => $state->getValue(), $states),
            $meaningIdent
        );
    }
}
