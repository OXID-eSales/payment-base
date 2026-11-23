<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Repository;

use Doctrine\DBAL\Connection;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Logging\ThrowableSummary;
use OxidEsales\PaymentBase\Repository\DoctrineContractRepository;
use OxidEsales\PaymentBase\Repository\UnreadableRowException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Sprint 147 (AW): a damaged row is refused. Before, a row without a state loaded as a draft and a
 * row with a damaged basket loaded with an empty basket.
 */
final class DoctrineContractRepositoryHydrationTest extends TestCase
{
    private Connection&MockObject $connection;
    private DoctrineContractRepository $repository;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->repository = new DoctrineContractRepository($this->connection, new NullLogger(), new ThrowableSummary([]));
    }

    public function testFindById_WhenTheRowIsComplete_HydratesTheContract(): void
    {
        $this->connection->method('fetchAssociative')->willReturn($this->row());

        $contract = $this->repository->findById('c-9');

        self::assertInstanceOf(PaymentContract::class, $contract);
        self::assertSame('pending', $contract->getStateValue());
        self::assertSame(10.0, $contract->getAmount());
    }

    public function testFindById_WhenTheStateIsEmpty_ThrowsUnreadableRow(): void
    {
        $this->connection->method('fetchAssociative')->willReturn($this->row(['OXSTATE' => '']));

        $this->expectException(UnreadableRowException::class);
        $this->expectExceptionMessage('OXSTATE');

        $this->repository->findById('c-9');
    }

    public function testFindById_WhenTheStateIsNull_ThrowsUnreadableRow(): void
    {
        $this->connection->method('fetchAssociative')->willReturn($this->row(['OXSTATE' => null]));

        $this->expectException(UnreadableRowException::class);

        $this->repository->findById('c-9');
    }

    public function testFindById_WhenTheBasketJsonIsDamaged_ThrowsUnreadableRow(): void
    {
        $this->connection->method('fetchAssociative')->willReturn($this->row(['OXBASKETDATA' => '{"items":[']));

        $this->expectException(UnreadableRowException::class);
        $this->expectExceptionMessage('OXBASKETDATA');

        $this->repository->findById('c-9');
    }

    public function testFindById_WhenTheConditionsJsonIsDamaged_ThrowsUnreadableRow(): void
    {
        $this->connection->method('fetchAssociative')->willReturn($this->row(['OXCONDITIONS' => 'not json']));

        $this->expectException(UnreadableRowException::class);
        $this->expectExceptionMessage('OXCONDITIONS');

        $this->repository->findById('c-9');
    }

    public function testFindById_WhenARowIsDamaged_TheMessageNamesTheRowAndNotItsContent(): void
    {
        $this->connection->method('fetchAssociative')->willReturn($this->row(['OXBASKETDATA' => 'secret-content']));

        try {
            $this->repository->findById('c-9');
            self::fail('expected a refusal');
        } catch (UnreadableRowException $refusal) {
            self::assertStringContainsString('c-9', $refusal->getMessage());
            self::assertStringNotContainsString('secret-content', $refusal->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'OXID' => 'c-9', 'OXSHOPID' => 1, 'OXUSERID' => 'u', 'OXORDERID' => null, 'OXSTATE' => 'pending',
            'OXSTATEREASON' => null,
            'OXBASKETDATA' => '{"items":[],"discounts":[],"totalGross":10.0,"totalNet":8.4,"totalVat":1.6,"currency":"EUR"}',
            'OXTERMS' => null, 'OXMETADATA' => null, 'OXCONDITIONS' => '[]', 'OXPROVIDER' => null,
            'OXPROVIDERORDERID' => null, 'OXPROVIDERDATA' => null, 'OXCREATED' => '2026-09-24 10:00:00',
            'OXUPDATED' => '2026-09-24 10:00:00', 'OXCOMMITTEDAT' => null, 'OXFULFILLEDAT' => null,
            'OXEXPIRESAT' => null, 'OXVERSION' => '7',
        ], $overrides);
    }
}
