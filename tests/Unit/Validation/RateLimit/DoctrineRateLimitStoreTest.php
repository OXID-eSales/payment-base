<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Validation\RateLimit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use OxidEsales\PaymentBase\Repository\IdempotencyRepositoryInterface;
use OxidEsales\PaymentBase\Validation\RateLimit\DoctrineRateLimitStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 29 (VRL): a rate limit that cannot count does not let the request through (it answered 0 = "under the limit").
 */
#[CoversClass(DoctrineRateLimitStore::class)]
final class DoctrineRateLimitStoreTest extends TestCase
{
    public function testADatabaseError_IsNotTakenAsUnderTheLimit(): void
    {
        $error = new DbalException('connection lost');
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willThrowException($error);
        $connection->method('fetchAssociative')->willThrowException($error);
        $connection->method('fetchOne')->willThrowException($error);
        $connection->method('insert')->willThrowException($error);
        $connection->method('update')->willThrowException($error);

        $store = new DoctrineRateLimitStore($connection, $this->createStub(IdempotencyRepositoryInterface::class));

        $this->expectException(DbalException::class);
        $store->increment('validate:k:1', 60);
    }

    public function testANewRow_ClearsExpiredRows(): void
    {
        $records = $this->createMock(IdempotencyRepositoryInterface::class);
        $records->expects($this->once())->method('deleteExpired');

        $count = (new DoctrineRateLimitStore($this->connection(affected: 1, count: '1'), $records))->increment('validate:k:1', 60);

        $this->assertSame(1, $count);
    }

    public function testACountedRow_DoesNotPurge(): void
    {
        $records = $this->createMock(IdempotencyRepositoryInterface::class);
        $records->expects($this->never())->method('deleteExpired');

        $count = (new DoctrineRateLimitStore($this->connection(affected: 2, count: '7'), $records))->increment('validate:k:1', 60);

        $this->assertSame(7, $count);
    }

    public function testTheIncrementIsOneUpsertOnTheUniqueKey(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('executeStatement')
            ->with($this->stringContains('ON DUPLICATE KEY UPDATE'))
            ->willReturn(2);
        $connection->method('fetchOne')->willReturn('2');
        $connection->expects($this->never())->method('insert');
        $connection->expects($this->never())->method('update');

        (new DoctrineRateLimitStore($connection, $this->createStub(IdempotencyRepositoryInterface::class)))->increment('validate:k:1', 60);
    }

    private function connection(int $affected, string $count): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willReturn($affected);
        $connection->method('fetchOne')->willReturn($count);

        return $connection;
    }
}
