<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use OxidEsales\PaymentBase\Logging\ThrowableSummary;
use OxidEsales\PaymentBase\Mail\DeferredOrderMail;
use OxidEsales\PaymentBase\Repository\DoctrineContractOrderMailStore;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * STRP-176 — the order mails wait on their contract row. The SQL against a
 * real table is covered by the integration suite; here: what is written,
 * what comes back, and which state each step moves from.
 */
final class DoctrineContractOrderMailStoreTest extends TestCase
{
    /** @var list<array{0: string, 1: array<string, string>}> */
    private array $statements = [];

    public function testStash_WritesTheMailsAsPendingAndAnswersWhetherTheRowExists(): void
    {
        $store = new DoctrineContractOrderMailStore($this->connection(affected: 1), new ThrowableSummary([]));

        self::assertTrue($store->stash('contract-1', [$this->mail()]));
        [$sql, $params] = $this->statements[0];
        self::assertStringContainsString('WHERE OXID = :id', $sql);
        self::assertSame('pending', $params['pending']);
        self::assertSame('contract-1', $params['id']);

        $gone = $this->connection(affected: 0);
        $gone->method('fetchAssociative')->willReturn(false);
        self::assertFalse((new DoctrineContractOrderMailStore($gone, new ThrowableSummary([])))->stash('gone', []));
    }

    /**
     * Sprint 28 (OMS) — MySQL reports changed rows, not matched rows: a rewrite
     * that re-renders the very same mails changes nothing, and the mails are
     * still kept. The store asks the row before answering.
     */
    public function testStash_WhenNothingChangedButTheRowAlreadyHoldsExactlyTheseMails_AnswersTrue(): void
    {
        $connection = $this->connection(affected: 0);
        $connection->method('fetchAssociative')->willReturnCallback(
            function (string $sql, array $params): array {
                $this->statements[] = [$sql, $params];

                return ['OXID' => 'contract-1'];
            }
        );

        self::assertTrue((new DoctrineContractOrderMailStore($connection, new ThrowableSummary([])))->stash('contract-1', [$this->mail()]));

        [$update, $updateParams] = $this->statements[0];
        [$select, $selectParams] = $this->statements[1];
        self::assertStringContainsString('UPDATE', $update);
        self::assertStringContainsString('OXORDERMAILSTATE = :pending', $select);
        self::assertStringContainsString('OXORDERMAILS = :mails', $select);
        self::assertStringContainsString('OXORDERMAILSENTAT IS NULL', $select);
        self::assertSame('contract-1', $selectParams['id']);
        self::assertSame($updateParams['mails'], $selectParams['mails']);
    }

    public function testStash_WhenTheKeptCheckFails_ThrowsWithoutTheMailContent(): void
    {
        $connection = $this->connection(affected: 0);
        $connection->method('fetchAssociative')->willThrowException(new class (
            "An exception occurred while executing 'SELECT …' with params [\"zoe@example.test\"]"
        ) extends DbalException {
            public function getSQLState(): string
            {
                return 'HY000';
            }
        });

        try {
            (new DoctrineContractOrderMailStore($connection, new ThrowableSummary([])))->stash('contract-1', [$this->mail()]);
            self::fail('a database error must not pass silently');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('contract-1', $e->getMessage());
            self::assertStringContainsString('SQLSTATE HY000', $e->getMessage());
            self::assertStringNotContainsString('zoe@example.test', $e->getMessage());
        }
    }

    public function testStash_WritesPlainAscii_SoTheLatin1ContractTableKeepsEveryCharacter(): void
    {
        (new DoctrineContractOrderMailStore($this->connection(affected: 1), new ThrowableSummary([])))->stash('contract-1', [$this->mail()]);

        self::assertSame(1, preg_match('/^[\x20-\x7E]*$/', $this->statements[0][1]['mails']));
    }

    public function testClaim_TakesOnlyAPendingMailOfANotStornoedOrder_AndReturnsItByteForByte(): void
    {
        $connection = $this->connection(affected: 1);
        (new DoctrineContractOrderMailStore($connection, new ThrowableSummary([])))->stash('contract-1', [$this->mail()]);
        $connection->method('fetchAssociative')->willReturn([
            'OXORDERID' => 'order-1',
            'OXSHOPID' => '3',
            'OXORDERMAILS' => $this->statements[0][1]['mails'],
        ]);

        $claimed = (new DoctrineContractOrderMailStore($connection, new ThrowableSummary([])))->claim('contract-1');

        [$sql, $params] = $this->statements[1];
        self::assertStringContainsString('c.OXORDERMAILSTATE = :pending AND o.OXSTORNO = 0', $sql);
        self::assertSame('sending', $params['sending']);
        self::assertNotNull($claimed);
        self::assertSame('order-1', $claimed->orderId);
        self::assertSame(3, $claimed->shopId);
        self::assertEquals([$this->mail()], $claimed->mails);
    }

    public function testClaim_WhenNothingWasPending_ReturnsNull(): void
    {
        // The other leg holds it, it went out, nothing was deferred, or the order was cancelled.
        $connection = $this->connection(affected: 0);
        $connection->expects($this->never())->method('fetchAssociative');

        self::assertNull((new DoctrineContractOrderMailStore($connection, new ThrowableSummary([])))->claim('contract-1'));
    }

    public function testClaim_WithGarbageInTheColumn_ReturnsNoMailsInsteadOfFailing(): void
    {
        $connection = $this->connection(affected: 1);
        $connection->method('fetchAssociative')->willReturn(['OXORDERID' => 'o', 'OXSHOPID' => 1, 'OXORDERMAILS' => '{nope']);

        self::assertSame([], (new DoctrineContractOrderMailStore($connection, new ThrowableSummary([])))->claim('contract-1')?->mails);
    }

    public function testMarkSent_ClearsTheContentAndStampsTheTime_OnlyFromSending(): void
    {
        (new DoctrineContractOrderMailStore($this->connection(affected: 1), new ThrowableSummary([])))->markSent('contract-1');

        [$sql, $params] = $this->statements[0];
        self::assertStringContainsString('OXORDERMAILS = NULL, OXORDERMAILSENTAT = NOW()', $sql);
        self::assertSame(['sent' => 'sent', 'sending' => 'sending', 'id' => 'contract-1'], $params);
    }

    public function testMarkFailed_KeepsOnlyTheUnsentMails(): void
    {
        (new DoctrineContractOrderMailStore($this->connection(affected: 1), new ThrowableSummary([])))->markFailed('contract-1', [$this->mail()]);

        $params = $this->statements[0][1];
        self::assertSame('failed', $params['failed']);
        self::assertCount(1, json_decode($params['mails'], true));
    }

    public function testDropByOrderId_ClearsPendingAndFailed_LeavesSentAndSendingAlone(): void
    {
        self::assertSame(1, (new DoctrineContractOrderMailStore($this->connection(affected: 1), new ThrowableSummary([])))->dropByOrderId('order-1'));

        [$sql, $params] = $this->statements[0];
        self::assertStringContainsString('OXORDERMAILSTATE IN (:pending, :failed)', $sql);
        self::assertSame('dropped', $params['dropped']);
        self::assertSame('order-1', $params['orderId']);
    }

    public function testADatabaseErrorBecomesARuntimeExceptionNamingTheContract(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willThrowException(new class ('gone away') extends DbalException {
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('contract-1');

        (new DoctrineContractOrderMailStore($connection, new ThrowableSummary([])))->markSent('contract-1');
    }

    /**
     * VR-16 O5 — older DBAL versions (the one the shop runs on pay1) put the
     * bound parameters into the message: the whole rendered mail. It must never
     * reach a log line through this exception.
     */
    public function testADatabaseErrorKeepsTheSqlStateButNotTheDriverMessage(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willThrowException(new class (
            "An exception occurred while executing 'UPDATE oe_payments_contract SET OXORDERMAILS = ?'"
            . " with params [\"<p>Dear Zoë, zoe@example.test, Hauptstr. 1</p>\"]:"
            . " SQLSTATE[42S22]: Column not found: 1054 Unknown column 'OXORDERMAILSTATE'"
        ) extends DbalException {
            public function getSQLState(): string
            {
                return '42S22';
            }
        });

        try {
            (new DoctrineContractOrderMailStore($connection, new ThrowableSummary([])))->stash('contract-1', [$this->mail()]);
            self::fail('a database error must not pass silently');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('contract-1', $e->getMessage());
            self::assertStringContainsString('SQLSTATE 42S22', $e->getMessage());
            self::assertStringNotContainsString('zoe@example.test', $e->getMessage());
            self::assertStringNotContainsString('with params', $e->getMessage());
        }
    }

    public function testAReadErrorKeepsTheSqlStateButNotTheDriverMessage(): void
    {
        $connection = $this->connection(affected: 1);
        $connection->method('fetchAssociative')->willThrowException(new class (
            "An exception occurred while executing 'SELECT …' with params [\"zoe@example.test\"]"
        ) extends DbalException {
        });

        try {
            (new DoctrineContractOrderMailStore($connection, new ThrowableSummary([])))->claim('contract-1');
            self::fail('a database error must not pass silently');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('contract-1', $e->getMessage());
            self::assertStringContainsString('SQLSTATE unknown', $e->getMessage());
            self::assertStringNotContainsString('zoe@example.test', $e->getMessage());
        }
    }

    private function connection(int $affected): Connection&MockObject
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params) use ($affected): int {
                $this->statements[] = [$sql, $params];

                return $affected;
            }
        );

        return $connection;
    }

    private function mail(): DeferredOrderMail
    {
        return new DeferredOrderMail(
            kind: DeferredOrderMail::KIND_CUSTOMER,
            from: 'order@shop.test',
            fromName: 'Mein Shop',
            recipients: [['zoe@example.test', 'Zoë 李']],
            replyTo: [['order@shop.test', 'Mein Shop']],
            cc: [],
            bcc: [['audit@shop.test', '']],
            subject: 'Ihre Bestellung bei OXID eSales (#42)',
            body: '<p>Summe 10,00 €</p>',
            altBody: 'Summe 10,00 €',
        );
    }
}
