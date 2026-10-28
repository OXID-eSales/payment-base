<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Admin\Help;

/**
 * Implemented by a provider module to add its own column to the shared contract-state Help table: the provider's
 * status for each OXID contract state. Tag the service `oe.payment.admin_state_help`; payment-base's admin API
 * (`adminPaymentContractStateHelp`) then shows the column. Without a registered provider the table has the OXID
 * columns only. Deliberately outside the GraphQL namespaces: a provider implements it without graphql installed.
 *
 * @since 1.7.0
 */
interface ContractStateHelpProviderInterface
{
    public const TAG = 'oe.payment.admin_state_help';

    /** Matches PaymentPanelProviderInterface::getProviderName() and the contract's provider (`stripe`, `mollie`...). */
    public function providerName(): string;

    /** Human name of the provider's status column ("Stripe", "Mollie"). */
    public function providerLabel(): string;

    /**
     * Provider status per row, keyed by {@see ContractStateHelpRow::key()} (`not_finished`, `pending`, `authorized`,
     * `ready_to_commit`, `committed`, `cancelled`, `expired`, `failed`). A missing or empty entry means "none".
     *
     * @return array<string, string>
     */
    public function providerStatuses(): array;
}
