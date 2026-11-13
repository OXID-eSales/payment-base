<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use OxidEsales\PaymentBase\Contract\IdempotencyRecord;
use OxidEsales\PaymentBase\Repository\DoctrineIdempotencyRepository;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for DoctrineIdempotencyRepository.
 *
 * Sprint 42: Idempotency implementation.
 */
#[CoversClass(\OxidEsales\PaymentBase\Repository\DoctrineIdempotencyRepository::class)]
#[Group('sprint-42')]
#[Group('idempotency')]
class DoctrineIdempotencyRepositoryTest extends TestCase
{
    #[Test]
    public function saveInsertsNewRecord(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('fetchOne')
            ->willReturn(0);

        $connection
            ->expects($this->once())
            ->method('insert')
            ->with(
                'oe_payments_idempotency',
                $this->callback(function (array $data) {
                    return $data['OXID'] === 'id_123'
                        && $data['OXKEY'] === 'capture:pi_abc'
                        && $data['OXORDERID'] === 'pi_abc'
                        && $data['OXOPERATION'] === 'capture'
                        && $data['OXSTATUS'] === 'processing'
                        && $data['OXRESULT'] === null;
                })
            );

        $repository = new DoctrineIdempotencyRepository($connection);
        $record = $this->createRecord();

        $repository->save($record);
    }

    #[Test]
    public function saveUpdatesExistingRecord(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('fetchOne')
            ->willReturn(1);

        $connection
            ->expects($this->once())
            ->method('update')
            ->with(
                'oe_payments_idempotency',
                $this->callback(function (array $data) {
                    return $data['OXID'] === 'id_123'
                        && $data['OXSTATUS'] === 'processing';
                }),
                ['OXID' => 'id_123']
            );

        $repository = new DoctrineIdempotencyRepository($connection);
        $record = $this->createRecord();

        $repository->save($record);
    }

    #[Test]
    public function findByKeyReturnsRecordWhenFound(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('fetchAssociative')
            ->with(
                $this->stringContains('OXKEY'),
                ['key' => 'capture:pi_abc']
            )
            ->willReturn([
                'OXID' => 'id_123',
                'OXKEY' => 'capture:pi_abc',
                'OXORDERID' => 'pi_abc',
                'OXOPERATION' => 'capture',
                'OXRESULT' => '{"success":true}',
                'OXSTATUS' => 'completed',
                'OXCREATED' => '2026-02-06 10:00:00',
                'OXEXPIRES' => '2026-02-07 10:00:00',
            ]);

        $repository = new DoctrineIdempotencyRepository($connection);
        $record = $repository->findByKey('capture:pi_abc');

        $this->assertNotNull($record);
        $this->assertSame('id_123', $record->getId());
        $this->assertSame('capture:pi_abc', $record->getKey());
        $this->assertSame('pi_abc', $record->getOrderId());
        $this->assertSame('capture', $record->getOperation());
        $this->assertSame('{"success":true}', $record->getResult());
        $this->assertSame('completed', $record->getStatus());
    }

    #[Test]
    public function findByKeyReturnsNullWhenNotFound(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn(false);

        $repository = new DoctrineIdempotencyRepository($connection);
        $result = $repository->findByKey('nonexistent');

        $this->assertNull($result);
    }

    #[Test]
    public function deleteExpiredRemovesExpiredRecords(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('DELETE'),
                $this->callback(function (array $params) {
                    return isset($params['now']);
                })
            )
            ->willReturn(3);

        $repository = new DoctrineIdempotencyRepository($connection);
        $count = $repository->deleteExpired();

        $this->assertSame(3, $count);
    }

    /**
     * Sprint 29 (VRL): was deleteExpiredReturnsZeroOnException, which pinned "a failed purge reports 0 deleted".
     * The purge now runs on every insert; a database error there is not "nothing to do".
     */
    #[Test]
    public function deleteExpiredLetsADatabaseErrorThrough(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('executeStatement')
            ->willThrowException(new \Doctrine\DBAL\Exception('DB error'));

        $repository = new DoctrineIdempotencyRepository($connection);

        $this->expectException(\Doctrine\DBAL\Exception::class);
        $repository->deleteExpired();
    }

    /**
     * Sprint 29 (VRL): the full purge deletes in batches of 500 until a batch comes back short.
     */
    #[Test]
    public function deleteExpiredRunsBatchesUntilOneComesBackShort(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->exactly(3))
            ->method('executeStatement')
            ->with($this->stringContains('LIMIT 500'))
            ->willReturnOnConsecutiveCalls(500, 500, 12);

        $this->assertSame(1012, (new DoctrineIdempotencyRepository($connection))->deleteExpired());
    }

    #[Test]
    public function deleteExpiredBatchDeletesOneBatch(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with($this->logicalAnd($this->stringContains('OXEXPIRES < :now'), $this->stringContains('LIMIT 500')))
            ->willReturn(500);

        $this->assertSame(500, (new DoctrineIdempotencyRepository($connection))->deleteExpiredBatch());
    }

    #[Test]
    public function findByKeyHydratesRecordWithNullResult(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn([
                'OXID' => 'id_456',
                'OXKEY' => 'refund:pi_xyz',
                'OXORDERID' => 'pi_xyz',
                'OXOPERATION' => 'refund',
                'OXRESULT' => null,
                'OXSTATUS' => 'processing',
                'OXCREATED' => '2026-02-06 10:00:00',
                'OXEXPIRES' => '2026-02-07 10:00:00',
            ]);

        $repository = new DoctrineIdempotencyRepository($connection);
        $record = $repository->findByKey('refund:pi_xyz');

        $this->assertNotNull($record);
        $this->assertNull($record->getResult());
        $this->assertSame('processing', $record->getStatus());
    }

    private function createRecord(): IdempotencyRecord
    {
        return new IdempotencyRecord(
            'id_123',
            'capture:pi_abc',
            'pi_abc',
            'capture',
            'processing',
            new DateTimeImmutable('2026-02-06 10:00:00'),
            new DateTimeImmutable('2026-02-07 10:00:00')
        );
    }
}
