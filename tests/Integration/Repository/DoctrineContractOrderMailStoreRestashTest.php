<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Repository;

use Doctrine\DBAL\Connection;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\PaymentBase\Logging\ThrowableSummary;
use OxidEsales\PaymentBase\Mail\DeferredOrderMail;
use OxidEsales\PaymentBase\Repository\DoctrineContractOrderMailStore;
use PHPUnit\Framework\Attributes\Group;

/**
 * Sprint 28 (OMS) — a rewritten order re-renders its mails; when the basket did
 * not change they are byte for byte the ones already on the contract. MySQL
 * reports such an UPDATE as "0 rows changed", and the store must still answer
 * that the mails are kept (pay1, 2026-11-06: the third checkout attempt on one
 * contract failed with order_mails_not_kept).
 */
#[Group('database')]
final class DoctrineContractOrderMailStoreRestashTest extends IntegrationTestCase
{
    private const ID = 'oms28_restash';

    private Connection $connection;

    private DoctrineContractOrderMailStore $store;

    public function setUp(): void
    {
        parent::setUp();
        $this->connection = ContainerFactory::getInstance()->getContainer()->get(ConnectionProviderInterface::class)->get();
        $this->store = new DoctrineContractOrderMailStore($this->connection, new ThrowableSummary([]));
        $this->connection->executeStatement('DELETE FROM oe_payments_contract WHERE OXID = ?', [self::ID]);
        $this->seedDraftContract();
    }

    public function tearDown(): void
    {
        $this->connection->executeStatement('DELETE FROM oe_payments_contract WHERE OXID = ?', [self::ID]);
        parent::tearDown();
    }

    public function testStash_OfByteIdenticalMailsOnAPendingRow_IsKept(): void
    {
        self::assertTrue($this->store->stash(self::ID, [$this->mail('<p>Summe 10,00 €</p>')]));
        $stored = $this->row();

        self::assertTrue(
            $this->store->stash(self::ID, [$this->mail('<p>Summe 10,00 €</p>')]),
            'the second stash of identical mails answered false'
        );
        self::assertSame($stored, $this->row());
        self::assertSame('pending', $stored['OXORDERMAILSTATE']);
    }

    public function testStash_OfChangedMailsOnAPendingRow_ReplacesThem(): void
    {
        self::assertTrue($this->store->stash(self::ID, [$this->mail('<p>Summe 10,00 €</p>')]));

        self::assertTrue($this->store->stash(self::ID, [$this->mail('<p>Summe 12,00 €</p>')]));
        self::assertStringContainsString('12,00', $this->row()['OXORDERMAILS']);
    }

    public function testStash_OnAnUnknownContract_IsNotKept(): void
    {
        self::assertFalse($this->store->stash('oms28_no_such_contract', [$this->mail('<p>Summe 10,00 €</p>')]));
    }

    private function seedDraftContract(): void
    {
        $this->connection->executeStatement(
            'INSERT INTO oe_payments_contract
                (OXID, OXSHOPID, OXUSERID, OXSTATE, OXBASKETDATA, OXCONDITIONS, OXCREATED, OXUPDATED, OXEXPIRESAT)
             VALUES (?, 1, ?, ?, ?, ?, NOW(), NOW(), NOW())',
            [self::ID, 'oms28_user', 'draft', '{}', '[]']
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT OXORDERMAILSTATE, OXORDERMAILS, OXORDERMAILSENTAT FROM oe_payments_contract WHERE OXID = ?',
            [self::ID]
        );
        self::assertNotFalse($row);

        return $row;
    }

    private function mail(string $body): DeferredOrderMail
    {
        return new DeferredOrderMail(
            kind: DeferredOrderMail::KIND_CUSTOMER,
            from: 'order@shop.test',
            fromName: 'Mein Shop',
            recipients: [['zoe@example.test', 'Zoë 李']],
            replyTo: [],
            cc: [],
            bcc: [],
            subject: 'Ihre Bestellung bei OXID eSales (#42)',
            body: $body,
            altBody: strip_tags($body),
        );
    }
}
