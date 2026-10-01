<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Admin\Help;

use OxidEsales\PaymentBase\Admin\Help\ContractStateHelp;
use OxidEsales\PaymentBase\Admin\Help\ContractStateHelpRow;
use OxidEsales\PaymentBase\Contract\ContractState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 14 (MOL-10) — the shared "Help" table: OXID Contract Status · Meaning, one row per contract
 * state a checkout can reach, in ladder order. Providers add their own column on top of these rows.
 */
final class ContractStateHelpTest extends TestCase
{
    private const EXPECTED = [
        [['not_finished'], 'PAYMENT_ADMIN_HELP_STATE_NOT_FINISHED'],
        [['pending'], 'PAYMENT_ADMIN_HELP_STATE_PENDING'],
        [['authorized'], 'PAYMENT_ADMIN_HELP_STATE_AUTHORIZED'],
        [['ready_to_commit'], 'PAYMENT_ADMIN_HELP_STATE_READY_TO_COMMIT'],
        [['committed', 'fulfilled'], 'PAYMENT_ADMIN_HELP_STATE_COMMITTED_FULFILLED'],
        [['cancelled'], 'PAYMENT_ADMIN_HELP_STATE_CANCELLED'],
        [['expired'], 'PAYMENT_ADMIN_HELP_STATE_EXPIRED'],
        [['failed'], 'PAYMENT_ADMIN_HELP_STATE_FAILED'],
    ];

    public function testRowsAreInLadderOrderWithOneMeaningEach(): void
    {
        $rows = (new ContractStateHelp())->rows();

        self::assertCount(count(self::EXPECTED), $rows);
        foreach ($rows as $i => $row) {
            self::assertInstanceOf(ContractStateHelpRow::class, $row);
            self::assertSame(self::EXPECTED[$i][0], $row->states, "row $i states");
            self::assertSame(self::EXPECTED[$i][1], $row->meaningIdent, "row $i ident");
        }
    }

    public function testEveryContractStateExceptDraftAppearsExactlyOnce(): void
    {
        $shown = array_merge(...array_map(static fn (ContractStateHelpRow $r): array => $r->states, (new ContractStateHelp())->rows()));
        $all = array_map(static fn (ContractState $s): string => $s->getValue(), [
            ContractState::notFinished(), ContractState::pending(), ContractState::authorized(),
            ContractState::readyToCommit(), ContractState::committed(), ContractState::fulfilled(),
            ContractState::cancelled(), ContractState::expired(), ContractState::failed(),
        ]);
        sort($all);
        sort($shown);

        self::assertSame($all, $shown);
    }

    public function testARowKnowsItsFirstStateAsKeyForProviderColumns(): void
    {
        $row = (new ContractStateHelp())->rows()[4];

        self::assertSame('committed', $row->key(), 'providers map their status by the row\'s first state');
    }

    #[DataProvider('languages')]
    public function testEveryIdentIsTranslated(string $language): void
    {
        $lang = $this->adminLang($language);
        $idents = array_merge(
            ContractStateHelp::SHARED_IDENTS,
            array_map(static fn (ContractStateHelpRow $r): string => $r->meaningIdent, (new ContractStateHelp())->rows())
        );
        foreach ($idents as $ident) {
            self::assertArrayHasKey($ident, $lang, "$ident missing in $language");
            self::assertNotSame('', trim($lang[$ident]));
        }
    }

    #[DataProvider('languages')]
    public function testTheContractStateLabelReadsOxidContractStatus(string $language): void
    {
        $lang = $this->adminLang($language);

        self::assertSame($language === 'en' ? 'OXID Contract Status' : 'OXID-Vertragsstatus', $lang['PAYMENT_ADMIN_CONTRACT_STATE']);
        self::assertSame($lang['PAYMENT_ADMIN_CONTRACT_STATE'], $lang['PAYMENT_ADMIN_HELP_COL_CONTRACT_STATE']);
    }

    /** @return iterable<string, array{string}> */
    public static function languages(): iterable
    {
        yield 'en' => ['en'];
        yield 'de' => ['de'];
    }

    /** @return array<string, string> */
    private function adminLang(string $language): array
    {
        $aLang = [];
        require dirname(__DIR__, 4) . "/views/admin_twig/{$language}/payment_admin_lang.php";

        return $aLang;
    }
}
