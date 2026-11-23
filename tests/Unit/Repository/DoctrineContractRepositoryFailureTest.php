<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DatabaseException;
use OxidEsales\PaymentBase\Logging\ThrowableSummary;
use OxidEsales\PaymentBase\Repository\DoctrineContractRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Sprint 147 (AW): `null` from a finder means "not found" and nothing else. A database error is an
 * exception: a webhook handler that reads it as "no contract" drops the provider's event.
 */
final class DoctrineContractRepositoryFailureTest extends TestCase
{
    private Connection&MockObject $connection;
    private DoctrineContractRepository $repository;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->repository = new DoctrineContractRepository($this->connection, new NullLogger(), new ThrowableSummary([]));
    }

    public function testFindById_WhenTheDatabaseFails_ThrowsAndDoesNotAnswerNotFound(): void
    {
        $this->databaseFails();

        $this->expectException(DatabaseException::class);

        $this->repository->findById('contract-1');
    }

    public function testFindByProviderOrderId_WhenTheDatabaseFails_Throws(): void
    {
        $this->databaseFails();

        $this->expectException(DatabaseException::class);

        $this->repository->findByProviderOrderId('cs_1');
    }

    public function testFindActiveByUserId_WhenTheDatabaseFails_Throws(): void
    {
        $this->databaseFails();

        $this->expectException(DatabaseException::class);

        $this->repository->findActiveByUserId('user-1');
    }

    public function testFindByOrderId_WhenTheDatabaseFails_Throws(): void
    {
        $this->databaseFails();

        $this->expectException(DatabaseException::class);

        $this->repository->findByOrderId('order-1');
    }

    public function testFindById_WhenNoRowExists_ReturnsNull(): void
    {
        $this->connection->method('fetchAssociative')->willReturn(false);

        self::assertNull($this->repository->findById('contract-1'));
    }

    public function testFindByOrderId_WhenNoRowExists_ReturnsNull(): void
    {
        $this->connection->method('fetchAssociative')->willReturn(false);

        self::assertNull($this->repository->findByOrderId('order-1'));
    }

    private function databaseFails(): void
    {
        $this->connection->method('fetchAssociative')->willThrowException(new DatabaseException('connection lost'));
    }
}
