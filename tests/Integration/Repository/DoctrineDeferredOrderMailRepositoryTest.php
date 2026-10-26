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
