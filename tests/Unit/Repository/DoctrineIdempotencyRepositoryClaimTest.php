<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OxidEsales\PaymentBase\Contract\IdempotencyRecord;
use OxidEsales\PaymentBase\Repository\DoctrineIdempotencyRepository;
use PHPUnit\Framework\TestCase;

/**
 * VR-22 A2 — the admin capture / refund claim was check-then-act: findByKey()
 * saw an expired or released record, save() ran SELECT COUNT then UPDATE, and
 * two concurrent re-claims both wrote and both got FRESH - two calls to the PSP.
 * claim() takes the key in one statement: an INSERT, or one guarded UPDATE of a
 * record whose time ran out.
 */
final class DoctrineIdempotencyRepositoryClaimTest extends TestCase
{
    public function testANewKeyIsClaimedByInserting(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('insert')->willReturn(1);
        $connection->expects(self::never())->method('executeStatement');

        self::assertTrue((new DoctrineIdempotencyRepository($connection))->claim($this->record(), $this->now()));
    }

    public function testAKeyWhoseRecordRanOutIsTakenOverByOneGuardedUpdate(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('insert')->willThrowException($this->uniqueViolation());
        $connection->expects(self::once())->method('executeStatement')
            ->with(
                self::callback(static fn (string $sql): bool => str_contains($sql, 'UPDATE oe_payments_idempotency')
                    && str_contains($sql, 'WHERE OXID = :id AND OXEXPIRES < :now')),
                self::callback(static fn (array $params): bool => $params['now'] === '2026-11-03 12:00:00'
                    && $params['OXSTATUS'] === 'in_flight'),
            )
            ->willReturn(1);

        self::assertTrue((new DoctrineIdempotencyRepository($connection))->claim($this->record(), $this->now()));
    }

    public function testAKeyHeldByALiveRecordIsNotClaimed(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('insert')->willThrowException($this->uniqueViolation());
        $connection->method('executeStatement')->willReturn(0);   // in flight, or completed and still retained

        self::assertFalse((new DoctrineIdempotencyRepository($connection))->claim($this->record(), $this->now()));
    }

    public function testADatabaseErrorWhileLookingUpIsNotANotFound(): void
    {
        // findByKey() answered null on any database error - and null reads as "fresh, go ahead".
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willThrowException(
            new ConnectionException($this->createMock(DriverException::class), null)
        );

        $this->expectException(ConnectionException::class);

        (new DoctrineIdempotencyRepository($connection))->findByKey('admin_payment:refund:order-1:client:k1');
    }

    private function record(): IdempotencyRecord
    {
        $now = $this->now();

        return new IdempotencyRecord(
            md5('admin_payment:refund:order-1:client:k1'),
            'admin_payment:refund:order-1:client:k1',
            'order-1',
            'refund',
            'in_flight',
            $now,
            $now->modify('+2 minutes'),
        );
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-03 12:00:00');
    }

    private function uniqueViolation(): UniqueConstraintViolationException
    {
        return new UniqueConstraintViolationException($this->createMock(DriverException::class), null);
    }
}
