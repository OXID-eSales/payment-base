<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

use OxidEsales\PaymentBase\Admin\Help\ContractStateHelpInterface;
use OxidEsales\PaymentBase\Admin\Help\ContractStateHelpProviderInterface;
use OxidEsales\PaymentBase\Admin\Help\ContractStateHelpRow;
use OxidEsales\PaymentBase\Admin\Help\HelpTranslatorInterface;

/**
 * The shared state Help (one PHP table, also behind the classic Help popups) with the meaning translated and, for
 * a provider that registered a {@see ContractStateHelpProviderInterface}, its status column.
 *
 * @since 1.7.0
 */
final class AdminStateHelpReader implements AdminStateHelpReaderInterface
{
    /**
     * @param iterable<ContractStateHelpProviderInterface> $providers
     */
    public function __construct(
        private readonly ContractStateHelpInterface $help,
        private readonly HelpTranslatorInterface $translator,
        private readonly iterable $providers,
    ) {
    }

    public function read(?string $provider, string $language): AdminStateHelp
    {
        $contributor = $provider === null ? null : $this->contributorFor($provider);
        $statuses = $contributor?->providerStatuses() ?? [];

        return new AdminStateHelp(
            $contributor?->providerLabel(),
            array_map(fn (ContractStateHelpRow $row): AdminStateHelpRow => $this->rowOf($row, $language, $contributor === null ? null : $statuses), $this->help->rows()),
        );
    }

    /**
     * @param array<string, string>|null $statuses null: no provider column at all
     */
    private function rowOf(ContractStateHelpRow $row, string $language, ?array $statuses): AdminStateHelpRow
    {
        $status = $statuses[$row->key()] ?? '';

        return new AdminStateHelpRow(
            strtoupper($row->key()),
            array_map('strtoupper', $row->states),
            $this->translator->translate($row->meaningIdent, $language),
            $status === '' ? null : $status,
        );
    }

    private function contributorFor(string $provider): ?ContractStateHelpProviderInterface
    {
        foreach ($this->providers as $candidate) {
            if (strcasecmp($candidate->providerName(), $provider) === 0) {
                return $candidate;
            }
        }

        return null;
    }
}
