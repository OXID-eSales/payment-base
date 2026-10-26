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
