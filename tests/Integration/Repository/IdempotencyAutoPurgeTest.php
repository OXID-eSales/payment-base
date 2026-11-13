<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface;
use OxidEsales\PaymentBase\Contract\IdempotencyRecord;
use OxidEsales\PaymentBase\Repository\DoctrineIdempotencyRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 29 (VRL) S5 — oe_payments_idempotency cleans itself on every insert, with no cron and no CLI.
 *
 * Every test first clears the table's expired rows, so the batch budget is spent on this test's rows only.
 */
#[Group('integration')]
final class IdempotencyAutoPurgeTest extends TestCase
{
    private const TABLE = 'oe_payments_idempotency';

    private Connection $connection;

    private DoctrineIdempotencyRepository $repository;

    private string $run;

    protected function setUp(): void
    {
        $this->connection = ContainerFactory::getInstance()->getContainer()
            ->get(ConnectionProviderInterface::class)
            ->get();
        $this->repository = new DoctrineIdempotencyRepository($this->connection);
        $this->run = 'vrl29s5-' . bin2hex(random_bytes(4));
        $this->connection->executeStatement('DELETE FROM ' . self::TABLE . ' WHERE OXEXPIRES < NOW()');
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement('DELETE FROM ' . self::TABLE . ' WHERE OXKEY LIKE :run', ['run' => '%' . $this->run . '%']);
    }

    public function testAnInsertBySave_RemovesExpiredRowsOfEveryKind(): void
    {
        $this->seedEveryKind();

        $this->repository->save($this->record('new-by-save', '+1 hour'));

        self::assertSame(['live-attempt', 'live-operation', 'new-by-save'], $this->keysOfThisRun());
    }

    public function testAnInsertByClaim_RemovesExpiredRows(): void
    {
        $this->seedEveryKind();

        self::assertTrue($this->repository->claim($this->record('new-by-claim', '+2 minutes'), new DateTimeImmutable()));

        self::assertSame(['live-attempt', 'live-operation', 'new-by-claim'], $this->keysOfThisRun());
    }

    public function testAnUpdate_DoesNotPurge(): void
    {
        $this->repository->save($this->record('updated', '+1 hour'));
        $this->seed('expired-after', 'completed', '-1 minute');

        $this->repository->save($this->record('updated', '+2 hours'));

        self::assertSame(['expired-after', 'updated'], $this->keysOfThisRun());
    }

    public function testOneInsertRemovesAtMostOneBatch(): void
    {
        $this->seedMany(501);

        $this->repository->save($this->record('new', '+1 hour'));

        self::assertCount(2, $this->keysOfThisRun(), '500 expired rows removed, 1 left, plus the new one');
    }

    public function testDeleteExpired_RemovesEverythingExpired_InBatches(): void
    {
        $this->seedMany(1001);

        self::assertGreaterThanOrEqual(1001, $this->repository->deleteExpired());
        self::assertSame([], $this->keysOfThisRun());
    }

    public function testAPurgedKeyIsClaimedLikeAFreshKey(): void
    {
        $this->seed('purged', 'in_flight', '-1 minute');
        $this->repository->save($this->record('trigger', '+1 hour'));

        self::assertTrue($this->repository->claim($this->record('purged', '+2 minutes'), new DateTimeImmutable()));
    }

    public function testARecordSavedAlreadyExpired_IsReadBack_UntilTheNextInsert(): void
    {
        $this->repository->save($this->record('released', '-1 second'));

        self::assertNotNull($this->repository->findByKey($this->run . ':released'), 'what save() wrote, findByKey() returns');

        $this->repository->save($this->record('next', '+1 hour'));

        self::assertNull($this->repository->findByKey($this->run . ':released'));
    }

    private function seedEveryKind(): void
    {
        $this->seed('expired-rate-limit', 'rate_limit', '-10 minutes');
        $this->seed('expired-attempt', 'released', '-1 second');
        $this->seed('expired-operation', 'completed', '-1 hour');
        $this->seed('expired-legacy-webhook', 'processed', '-1 day');
        $this->seed('live-attempt', 'in_flight', '+2 minutes');
        $this->seed('live-operation', 'completed', '+1 day');
    }

    private function seedMany(int $count): void
    {
        $rows = [];
        $expires = (new DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
        for ($i = 0; $i < $count; $i++) {
            $key = $this->run . ':bulk-' . $i;
            $rows[] = sprintf("('%s', '%s', '', 'refund', '{}', 'completed', '%s', '%s')", md5($key), $key, $expires, $expires);
        }
        $this->connection->executeStatement(
            'INSERT INTO ' . self::TABLE . ' (OXID, OXKEY, OXORDERID, OXOPERATION, OXRESULT, OXSTATUS, OXCREATED, OXEXPIRES) VALUES '
            . implode(',', $rows)
        );
    }

    private function seed(string $name, string $status, string $expiresIn): void
    {
        $now = new DateTimeImmutable();
        $key = $this->run . ':' . $name;
        $this->connection->insert(self::TABLE, [
            'OXID' => md5($key),
            'OXKEY' => $key,
            'OXORDERID' => '',
            'OXOPERATION' => 'refund',
            'OXRESULT' => '{}',
            'OXSTATUS' => $status,
            'OXCREATED' => $now->modify('-2 days')->format('Y-m-d H:i:s'),
            'OXEXPIRES' => $now->modify($expiresIn)->format('Y-m-d H:i:s'),
        ]);
    }

    private function record(string $name, string $expiresIn): IdempotencyRecord
    {
        $now = new DateTimeImmutable();
        $key = $this->run . ':' . $name;

        return new IdempotencyRecord(md5($key), $key, '', 'refund', 'in_flight', $now, $now->modify($expiresIn));
    }

    /**
     * @return list<string>
     */
    private function keysOfThisRun(): array
    {
        /** @var list<string> $keys */
        $keys = $this->connection->fetchFirstColumn(
            'SELECT OXKEY FROM ' . self::TABLE . ' WHERE OXKEY LIKE :run ORDER BY OXKEY',
            ['run' => '%' . $this->run . '%']
        );

        return array_map(fn (string $key): string => substr($key, strlen($this->run) + 1), $keys);
    }
}
