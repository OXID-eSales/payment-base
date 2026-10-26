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
use OxidEsales\PaymentBase\Mail\DeferredOrderMail;
use OxidEsales\PaymentBase\Repository\DoctrineDeferredOrderMailRepository;
use PHPUnit\Framework\Attributes\Group;

/**
 * STRP-176 — the stored copy of a rendered order mail against MySQL.
 */
#[Group('database')]
final class DoctrineDeferredOrderMailRepositoryTest extends IntegrationTestCase
{
    private Connection $connection;
    private DoctrineDeferredOrderMailRepository $repository;

    public function setUp(): void
    {
        parent::setUp();
        $this->connection = ContainerFactory::getInstance()->getContainer()
            ->get(ConnectionProviderInterface::class)->get();
        $this->repository = new DoctrineDeferredOrderMailRepository($this->connection);
    }

    public function tearDown(): void
    {
        parent::tearDown();
        $this->connection->executeStatement(
            "DELETE FROM oe_payments_order_mail WHERE OXORDERID LIKE 'pbtest\\_mail\\_%'"
        );
        $this->connection->executeStatement("DELETE FROM oxorder WHERE OXID LIKE 'pbtest\\_mail\\_%'");
    }

    public function testFindUnsentByOrderId_ReturnsTheMailsOfALiveOrderAsStored(): void
    {
        $this->seedOrder('pbtest_mail_live', storno: 0);
        $this->repository->save('pbtest_mail_live', 1, [$this->mail('customer'), $this->mail('owner')]);

        $found = $this->repository->findUnsentByOrderId('pbtest_mail_live');

        self::assertSame(['customer', 'owner'], array_map(static fn ($m): string => $m->mail->kind, $found));
        self::assertEquals($this->mail('customer'), $found[0]->mail);
        self::assertSame(1, $found[0]->shopId);
    }

    public function testFindUnsentByOrderId_IgnoresStornoedAndMissingOrders(): void
    {
        $this->seedOrder('pbtest_mail_storno', storno: 1);
        $this->repository->save('pbtest_mail_storno', 1, [$this->mail('customer')]);
        $this->repository->save('pbtest_mail_gone', 1, [$this->mail('customer')]);

        self::assertSame([], $this->repository->findUnsentByOrderId('pbtest_mail_storno'));
        self::assertSame([], $this->repository->findUnsentByOrderId('pbtest_mail_gone'));
    }

    public function testClaim_SucceedsOnceUntilReleased(): void
    {
        $this->seedOrder('pbtest_mail_claim', storno: 0);
        $this->repository->save('pbtest_mail_claim', 1, [$this->mail('customer')]);
        $mailId = $this->repository->findUnsentByOrderId('pbtest_mail_claim')[0]->id;

        self::assertTrue($this->repository->claim($mailId));
        self::assertFalse($this->repository->claim($mailId), 'the other leg must not get it');
        self::assertSame([], $this->repository->findUnsentByOrderId('pbtest_mail_claim'), 'a claimed mail is not unsent');

        $this->repository->release($mailId);

        self::assertTrue($this->repository->claim($mailId));
    }

    public function testDelete_RemovesTheOneMail(): void
    {
        $this->seedOrder('pbtest_mail_del', storno: 0);
        $this->repository->save('pbtest_mail_del', 1, [$this->mail('customer'), $this->mail('owner')]);
        $mailId = $this->repository->findUnsentByOrderId('pbtest_mail_del')[0]->id;

        $this->repository->delete($mailId);

        self::assertSame(1, $this->countFor('pbtest_mail_del'));
    }

    private function seedOrder(string $orderId, int $storno): void
    {
        $this->connection->insert('oxorder', ['OXID' => $orderId, 'OXSHOPID' => 1, 'OXSTORNO' => $storno]);
    }

    public function testSave_KeepsTheRenderedMailByteForByte_IncludingNonLatinText(): void
    {
        $body = '<p>Gesamtbetrag 20.595,95 € – danke, Zoë 李</p>';

        $this->repository->save('pbtest_mail_order1', 1, [$this->mail('customer', $body)]);

        $row = $this->connection->fetchAssociative(
            'SELECT OXBODY, OXSUBJECT, OXRECIPIENTS, OXSHOPID FROM oe_payments_order_mail WHERE OXORDERID = ?',
            ['pbtest_mail_order1']
        );
        self::assertIsArray($row);
        self::assertSame($body, $row['OXBODY']);
        self::assertSame('Ihre Bestellung über 20.595,95 € (#42)', $row['OXSUBJECT']);
        self::assertSame([['zoe@example.invalid', 'Zoë 李']], json_decode((string) $row['OXRECIPIENTS'], true));
        self::assertSame(1, (int) $row['OXSHOPID']);
    }

    public function testDeleteByOrderId_RemovesThatOrdersMailsOnly(): void
    {
        $this->repository->save('pbtest_mail_order2', 1, [$this->mail('customer'), $this->mail('owner')]);
        $this->repository->save('pbtest_mail_order3', 1, [$this->mail('customer')]);

        self::assertSame(2, $this->repository->deleteByOrderId('pbtest_mail_order2'));

        self::assertSame(0, $this->countFor('pbtest_mail_order2'));
        self::assertSame(1, $this->countFor('pbtest_mail_order3'));
    }

    private function countFor(string $orderId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM oe_payments_order_mail WHERE OXORDERID = ?',
            [$orderId]
        );
    }

    private function mail(string $kind, string $body = '<p>Danke</p>'): DeferredOrderMail
    {
        return new DeferredOrderMail(
            kind: $kind,
            from: 'reply@myoxideshop.com',
            fromName: 'OXID eShop',
            recipients: [['zoe@example.invalid', 'Zoë 李']],
            replyTo: [],
            cc: [],
            bcc: [],
            subject: 'Ihre Bestellung über 20.595,95 € (#42)',
            body: $body,
            altBody: 'Danke',
        );
    }
}
