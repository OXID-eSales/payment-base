<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Repository;

use Doctrine\DBAL\Connection;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Repository\DoctrineContractRepository;
use OxidEsales\PaymentBase\Repository\StaleContractException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * MOL-17 — optimistic concurrency on the contract row: the UPDATE matches the loaded version and moves
 * it one up; a copy that lost the race is refused instead of overwriting the newer row.
 */
#[CoversClass(DoctrineContractRepository::class)]
final class DoctrineContractRepositoryVersioningTest extends TestCase
{
    private Connection&MockObject $connection;
    private DoctrineContractRepository $repository;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->repository = new DoctrineContractRepository($this->connection);
    }

    public function testUpdateMatchesTheLoadedVersionAndMovesItOneUp(): void
    {
        $contract = $this->contractAtVersion(3);

        $this->connection->expects(self::once())->method('update')
            ->with(
                'oe_payments_contract',
                self::callback(static fn (array $data) => $data['OXVERSION'] === 4),
                ['OXID' => 'c-1', 'OXVERSION' => 3],
            )
            ->willReturn(1);
        $this->connection->expects(self::never())->method('insert');

        $this->repository->save($contract);

        self::assertSame(4, $contract->toArray()['version'], 'the in-memory copy follows the row');
    }

    public function testANewContractIsInsertedAtVersionZero(): void
    {
        $contract = $this->contractAtVersion(0);
        $this->connection->method('update')->willReturn(0);
        $this->connection->method('fetchOne')->willReturn(false);
        $this->connection->expects(self::once())->method('insert')
            ->with('oe_payments_contract', self::callback(static fn (array $data) => $data['OXVERSION'] === 0));

        $this->repository->save($contract);

        self::assertSame(0, $contract->toArray()['version']);
    }

    public function testAStaleCopyIsRefused(): void
    {
        $contract = $this->contractAtVersion(3);
        $this->connection->method('update')->willReturn(0);
        $this->connection->method('fetchOne')->willReturn('5');
        $this->connection->expects(self::never())->method('insert');

        try {
            $this->repository->save($contract);
            self::fail('a stale save must be refused');
        } catch (StaleContractException $e) {
            self::assertSame('c-1', $e->contractId);
            self::assertSame(3, $e->expectedVersion);
            self::assertSame(5, $e->currentVersion);
        }

        self::assertSame(3, $contract->toArray()['version'], 'a refused save leaves the copy where it was');
    }

    public function testHydrationReadsTheRowVersion(): void
    {
        $this->connection->method('fetchAssociative')->willReturn([
            'OXID' => 'c-9', 'OXSHOPID' => 1, 'OXUSERID' => 'u', 'OXORDERID' => null, 'OXSTATE' => 'draft',
            'OXSTATEREASON' => null, 'OXBASKETDATA' => json_encode($this->snapshot()->toArray()), 'OXTERMS' => null,
            'OXMETADATA' => null, 'OXCONDITIONS' => '[]', 'OXPROVIDER' => null, 'OXPROVIDERORDERID' => null,
            'OXPROVIDERDATA' => null, 'OXCREATED' => '2026-09-24 10:00:00', 'OXUPDATED' => '2026-09-24 10:00:00',
            'OXCOMMITTEDAT' => null, 'OXFULFILLEDAT' => null, 'OXEXPIRESAT' => null, 'OXVERSION' => '7',
        ]);

        $contract = $this->repository->findById('c-9');

        self::assertInstanceOf(PaymentContract::class, $contract);
        self::assertSame(7, $contract->toArray()['version']);
    }

    // MOL-17 Story 4: the sweep the reconciliation command runs on.
    public function testFindByStateAndProviderQueriesExactlyThatAndHydratesRows(): void
    {
        $this->connection->expects(self::once())->method('fetchAllAssociative')
            ->with(
                self::callback(static fn (string $sql) => str_contains($sql, 'OXSTATE = :state') && str_contains($sql, 'OXPROVIDER = :provider') && str_contains($sql, 'LIMIT 5')),
                ['state' => 'committed', 'provider' => 'mollie'],
            )
            ->willReturn([[
                'OXID' => 'c-3', 'OXSHOPID' => 1, 'OXUSERID' => 'u', 'OXORDERID' => 'o', 'OXSTATE' => 'committed',
                'OXSTATEREASON' => null, 'OXBASKETDATA' => json_encode($this->snapshot()->toArray()), 'OXTERMS' => null,
                'OXMETADATA' => null, 'OXCONDITIONS' => '[]', 'OXPROVIDER' => 'mollie', 'OXPROVIDERORDERID' => 'tr_3',
                'OXPROVIDERDATA' => null, 'OXCREATED' => '2026-09-24 10:00:00', 'OXUPDATED' => '2026-09-24 10:00:00',
                'OXCOMMITTEDAT' => '2026-09-24 10:00:00', 'OXFULFILLEDAT' => null, 'OXEXPIRESAT' => null, 'OXVERSION' => '2',
            ]]);

        $found = $this->repository->findByStateAndProvider('committed', 'mollie', 5);

        self::assertCount(1, $found);
        self::assertSame('tr_3', $found[0]->getProviderOrderId());
        self::assertSame('committed', $found[0]->getStateValue());
    }

    private function contractAtVersion(int $version): PaymentContract
    {
        $contract = PaymentContract::fromArray([
            'id' => 'c-1', 'shopId' => 1, 'userId' => 'u', 'state' => 'draft', 'version' => $version,
            'basketSnapshot' => $this->snapshot()->toArray(), 'conditions' => [],
            'createdAt' => '2026-09-24 10:00:00', 'updatedAt' => '2026-09-24 10:00:00',
        ]);
        self::assertSame($version, $contract->toArray()['version']);

        return $contract;
    }

    private function snapshot(): BasketSnapshot
    {
        return BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 10.0, 'totalNet' => 8.4, 'totalVat' => 1.6, 'currency' => 'EUR',
        ]);
    }
}
