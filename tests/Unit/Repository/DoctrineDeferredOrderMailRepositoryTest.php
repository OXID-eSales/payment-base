<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Driver\Exception as DriverException;
use OxidEsales\PaymentBase\Mail\DeferredOrderMail;
use OxidEsales\PaymentBase\Repository\DoctrineDeferredOrderMailRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * STRP-176 — the order mails core rendered during early order creation, kept
 * until the PSP confirms the payment.
 */
final class DoctrineDeferredOrderMailRepositoryTest extends TestCase
{
    public function testSave_WritesOneRowPerMailWithTheOrderAndShop(): void
    {
        $rows = [];
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(2))
            ->method('insert')
            ->willReturnCallback(function (string $table, array $data) use (&$rows): int {
                $rows[] = [$table, $data];

                return 1;
            });

        (new DoctrineDeferredOrderMailRepository($connection))->save('order-1', 1, [
            $this->mail(DeferredOrderMail::KIND_CUSTOMER),
            $this->mail(DeferredOrderMail::KIND_OWNER),
        ]);

        self::assertSame('oe_payments_order_mail', $rows[0][0]);
        self::assertSame('order-1', $rows[0][1]['OXORDERID']);
        self::assertSame(1, $rows[0][1]['OXSHOPID']);
        self::assertSame(DeferredOrderMail::KIND_CUSTOMER, $rows[0][1]['OXKIND']);
        self::assertSame(DeferredOrderMail::KIND_OWNER, $rows[1][1]['OXKIND']);
        self::assertSame(32, strlen($rows[0][1]['OXID']));
        self::assertNotSame($rows[0][1]['OXID'], $rows[1][1]['OXID']);
    }

    public function testSave_KeepsEveryFieldCoreSetOnTheMailer(): void
    {
        $data = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('insert')
            ->willReturnCallback(function (string $table, array $row) use (&$data): int {
                $data = $row;

                return 1;
            });

        (new DoctrineDeferredOrderMailRepository($connection))
            ->save('order-1', 1, [$this->mail(DeferredOrderMail::KIND_CUSTOMER)]);

        self::assertSame('order@shop.test', $data['OXFROM']);
        self::assertSame('My Shop', $data['OXFROMNAME']);
        self::assertSame('[["jane@example.test","Jane Doe"]]', $data['OXRECIPIENTS']);
        self::assertSame('[["order@shop.test","My Shop"]]', $data['OXREPLYTO']);
        self::assertSame('[]', $data['OXCC']);
        self::assertSame('[["audit@shop.test",""]]', $data['OXBCC']);
        self::assertSame('Your order (#42)', $data['OXSUBJECT']);
        self::assertSame('<p>Thank you</p>', $data['OXBODY']);
        self::assertSame('Thank you', $data['OXALTBODY']);
    }

    public function testSave_WithNoMails_WritesNothing(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('insert');

        (new DoctrineDeferredOrderMailRepository($connection))->save('order-1', 1, []);
    }

    public function testSave_WhenTheDatabaseFails_ThrowsRuntimeException(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('insert')
            ->willThrowException(new ConnectionLost($this->createMock(DriverException::class), null));

        $this->expectException(RuntimeException::class);

        (new DoctrineDeferredOrderMailRepository($connection))
            ->save('order-1', 1, [$this->mail(DeferredOrderMail::KIND_CUSTOMER)]);
    }

    public function testDeleteByOrderId_RemovesEveryMailOfTheOrderAndReportsTheCount(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('delete')
            ->with('oe_payments_order_mail', ['OXORDERID' => 'order-1'])
            ->willReturn(2);

        self::assertSame(2, (new DoctrineDeferredOrderMailRepository($connection))->deleteByOrderId('order-1'));
    }

    public function testFindUnsentByOrderId_RebuildsTheStoredMails(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([[
            'OXID' => 'mail-1',
            'OXSHOPID' => '3',
            'OXKIND' => 'customer',
            'OXFROM' => 'order@shop.test',
            'OXFROMNAME' => 'My Shop',
            'OXRECIPIENTS' => '[["jane@example.test","Jane Doe"]]',
            'OXREPLYTO' => '[["order@shop.test","My Shop"]]',
            'OXCC' => '[]',
            'OXBCC' => '[["audit@shop.test",""]]',
            'OXSUBJECT' => 'Your order (#42)',
            'OXBODY' => '<p>Thank you</p>',
            'OXALTBODY' => 'Thank you',
        ]]);

        $found = (new DoctrineDeferredOrderMailRepository($connection))->findUnsentByOrderId('order-1');

        self::assertCount(1, $found);
        self::assertSame('mail-1', $found[0]->id);
        self::assertSame(3, $found[0]->shopId);
        self::assertEquals($this->mail(DeferredOrderMail::KIND_CUSTOMER), $found[0]->mail);
    }

    public function testFindUnsentByOrderId_AsksOnlyForUnclaimedMailsOfALiveOrder(): void
    {
        // A stornoed or deleted order is never confirmed - even when a late
        // payment commits its contract after the cleanup cancelled it.
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(
                $this->callback(static fn (string $sql): bool => str_contains($sql, 'OXCLAIMEDAT IS NULL')
                    && str_contains($sql, 'JOIN oxorder')
                    && str_contains($sql, 'OXSTORNO = 0')),
                ['orderId' => 'order-1']
            )
            ->willReturn([]);

        self::assertSame([], (new DoctrineDeferredOrderMailRepository($connection))->findUnsentByOrderId('order-1'));
    }

    public function testClaim_WhenTheGuardedUpdateTookTheRow_ReturnsTrue(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('OXCLAIMEDAT IS NULL'), ['id' => 'mail-1'])
            ->willReturn(1);

        self::assertTrue((new DoctrineDeferredOrderMailRepository($connection))->claim('mail-1'));
    }

    public function testClaim_WhenAnotherSenderHoldsTheRow_ReturnsFalse(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(0);

        self::assertFalse((new DoctrineDeferredOrderMailRepository($connection))->claim('mail-1'));
    }

    public function testRelease_ClearsTheClaim(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('update')
            ->with('oe_payments_order_mail', ['OXCLAIMEDAT' => null], ['OXID' => 'mail-1']);

        (new DoctrineDeferredOrderMailRepository($connection))->release('mail-1');
    }

    public function testDelete_RemovesTheOneMail(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('delete')
            ->with('oe_payments_order_mail', ['OXID' => 'mail-1']);

        (new DoctrineDeferredOrderMailRepository($connection))->delete('mail-1');
    }

    private function mail(string $kind): DeferredOrderMail
    {
        return new DeferredOrderMail(
            kind: $kind,
            from: 'order@shop.test',
            fromName: 'My Shop',
            recipients: [['jane@example.test', 'Jane Doe']],
            replyTo: [['order@shop.test', 'My Shop']],
            cc: [],
            bcc: [['audit@shop.test', '']],
            subject: 'Your order (#42)',
            body: '<p>Thank you</p>',
            altBody: 'Thank you',
        );
    }
}
