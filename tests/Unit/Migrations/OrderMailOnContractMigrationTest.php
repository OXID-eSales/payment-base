<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Migrations;

use OxidEsales\PaymentBase\Migrations\Version20261028100000;
use PHPUnit\Framework\TestCase;

/**
 * STRP-176 follow-up — the 1.5.0 rows of oe_payments_order_mail become the
 * order-mail columns of their contract. The SQL around it is covered by the
 * integration suite; the conversion is pinned here.
 */
final class OrderMailOnContractMigrationTest extends TestCase
{
    public function testGroupByContract_PutsCustomerAndOwnerMailOfOneOrderOnItsContractAsPending(): void
    {
        $moved = Version20261028100000::groupByContract([
            $this->row('contract-1', 'customer'),
            $this->row('contract-1', 'owner'),
        ]);

        self::assertSame(['contract-1'], array_keys($moved));
        [$state, $json] = $moved['contract-1'];
        self::assertSame('pending', $state);
        self::assertSame(['customer', 'owner'], array_column(json_decode($json, true), 'kind'));
    }

    public function testGroupByContract_ClaimedButUnsentMailMakesTheContractFailed(): void
    {
        // A claim left set means a send died halfway: we cannot know whether
        // the customer got it, so nothing sends it again by itself.
        $moved = Version20261028100000::groupByContract([
            $this->row('contract-1', 'customer', claimedAt: '2026-10-27 10:00:00'),
            $this->row('contract-1', 'owner'),
        ]);

        self::assertSame('failed', $moved['contract-1'][0]);
    }

    public function testGroupByContract_KeepsEveryFieldInTheShapeTheStoreReads(): void
    {
        $moved = Version20261028100000::groupByContract([$this->row('contract-1', 'customer')]);

        self::assertSame(
            [[
                'kind' => 'customer',
                'from' => 'order@shop.test',
                'fromName' => 'Shop',
                'recipients' => [['zoe@example.test', 'Zoë 李']],
                'replyTo' => [['order@shop.test', 'Shop']],
                'cc' => [],
                'bcc' => [['audit@shop.test', '']],
                'subject' => 'Ihre Bestellung (#42)',
                'body' => '<p>Summe 10,00 €</p>',
                'altBody' => 'Summe 10,00 €',
            ]],
            json_decode($moved['contract-1'][1], true)
        );
    }

    public function testGroupByContract_WritesAsciiJson_SoTheLatin1ContractColumnKeepsEveryCharacter(): void
    {
        $json = Version20261028100000::groupByContract([$this->row('contract-1', 'customer')])['contract-1'][1];

        self::assertSame(1, preg_match('/^[\x20-\x7E]*$/', $json), 'only printable ASCII');
        self::assertStringContainsString('\u20ac', $json);
    }

    public function testGroupByContract_WithoutRows_MovesNothing(): void
    {
        self::assertSame([], Version20261028100000::groupByContract([]));
    }

    /**
     * @return array<string, string|null>
     */
    private function row(string $contractId, string $kind, ?string $claimedAt = null): array
    {
        return [
            'CONTRACTID' => $contractId,
            'OXKIND' => $kind,
            'OXCLAIMEDAT' => $claimedAt,
            'OXFROM' => 'order@shop.test',
            'OXFROMNAME' => 'Shop',
            'OXRECIPIENTS' => '[["zoe@example.test","Zoë 李"]]',
            'OXREPLYTO' => '[["order@shop.test","Shop"]]',
            'OXCC' => '[]',
            'OXBCC' => '[["audit@shop.test",""]]',
            'OXSUBJECT' => 'Ihre Bestellung (#42)',
            'OXBODY' => '<p>Summe 10,00 €</p>',
            'OXALTBODY' => 'Summe 10,00 €',
        ];
    }
}
