<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Service;

use OxidEsales\PaymentBase\Admin\Help\ContractStateHelp;
use OxidEsales\PaymentBase\Admin\Help\ContractStateHelpProviderInterface;
use OxidEsales\PaymentBase\Admin\Help\HelpTranslatorInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminStateHelpReader;
use PHPUnit\Framework\TestCase;

final class AdminStateHelpReaderTest extends TestCase
{
    public function testRead_NoProviderRegistered_RowsHaveNoProviderColumn(): void
    {
        $help = $this->reader([])->read('stripe', 'en');

        self::assertNull($help->providerLabel);
        self::assertCount(8, $help->rows);
        foreach ($help->rows as $row) {
            self::assertNull($row->providerStatus);
        }
    }

    public function testRead_WithoutProviderArgument_ListsTheSharedRowsOnly(): void
    {
        $help = $this->reader([$this->stripe()])->read(null, 'en');

        self::assertNull($help->providerLabel);
        self::assertNull($help->rows[0]->providerStatus);
    }

    public function testRead_RegisteredProvider_AddsItsLabelAndStatusPerRowKey(): void
    {
        $help = $this->reader([$this->stripe()])->read('stripe', 'en');

        self::assertSame('Stripe', $help->providerLabel);
        $byState = [];
        foreach ($help->rows as $row) {
            $byState[$row->state] = $row->providerStatus;
        }
        self::assertSame('requires_capture', $byState['AUTHORIZED']);
        self::assertNull($byState['PENDING'], 'a row the provider lists nothing for has no status');
    }

    public function testRead_Rows_CarryStateStatesAndTheTranslatedMeaning(): void
    {
        $help = $this->reader([])->read(null, 'de');

        $committed = $help->rows[4];
        self::assertSame('COMMITTED', $committed->state);
        self::assertSame(['COMMITTED', 'FULFILLED'], $committed->states);
        self::assertSame('de:PAYMENT_ADMIN_HELP_STATE_COMMITTED_FULFILLED', $committed->meaning);
    }

    public function testRead_ProviderNameIsMatchedCaseInsensitively(): void
    {
        self::assertSame('Stripe', $this->reader([$this->stripe()])->read('STRIPE', 'en')->providerLabel);
    }

    /**
     * @param list<ContractStateHelpProviderInterface> $providers
     */
    private function reader(array $providers): AdminStateHelpReader
    {
        $translator = new class implements HelpTranslatorInterface {
            public function translate(string $ident, string $language): string
            {
                return $language . ':' . $ident;
            }
        };

        return new AdminStateHelpReader(new ContractStateHelp(), $translator, $providers);
    }

    private function stripe(): ContractStateHelpProviderInterface
    {
        return new class implements ContractStateHelpProviderInterface {
            public function providerName(): string
            {
                return 'stripe';
            }

            public function providerLabel(): string
            {
                return 'Stripe';
            }

            public function providerStatuses(): array
            {
                return ['authorized' => 'requires_capture', 'pending' => ''];
            }
        };
    }
}
