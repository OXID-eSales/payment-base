<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Repository;

use Doctrine\DBAL\Connection;
use OxidEsales\PaymentBase\Repository\DeferredOrderMailRepositoryInterface;
use OxidEsales\PaymentBase\Repository\DoctrineNotFinishedOrderRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * STRP-176 — an abandoned checkout is cancelled by the cleanup command or by
 * the next checkout attempt, never paid. The order mails it stored would hold
 * the customer's data for nothing, and must never go out.
 */
final class DoctrineNotFinishedOrderRepositoryMailTest extends TestCase
{
    public function testCancelOrder_WhenTheStornoTook_DropsTheStoredOrderMails(): void
    {
        $mails = $this->createMock(DeferredOrderMailRepositoryInterface::class);
        $mails->expects($this->once())->method('deleteByOrderId')->with('order-1');

        self::assertTrue($this->repository(affectedRows: 1, mails: $mails)->cancelOrder('order-1'));
    }

    public function testCancelOrder_WhenTheOrderFinishedInBetween_KeepsItsMails(): void
    {
        // The guarded UPDATE touched nothing: the checkout completed meanwhile,
        // and its mail is about to be sent.
        $mails = $this->createMock(DeferredOrderMailRepositoryInterface::class);
        $mails->expects($this->never())->method('deleteByOrderId');

        self::assertFalse($this->repository(affectedRows: 0, mails: $mails)->cancelOrder('order-1'));
    }

    public function testCancelOrder_WhenDroppingTheMailsFails_StillReportsTheStorno(): void
    {
        $mails = $this->createMock(DeferredOrderMailRepositoryInterface::class);
        $mails->method('deleteByOrderId')->willThrowException(new RuntimeException('db down'));

        self::assertTrue($this->repository(affectedRows: 1, mails: $mails)->cancelOrder('order-1'));
    }

    private function repository(int $affectedRows, DeferredOrderMailRepositoryInterface $mails): DoctrineNotFinishedOrderRepository
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn($affectedRows);

        return new DoctrineNotFinishedOrderRepository($connection, $mails);
    }
}
