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
 * VR-22 A2 — the atomic claim against the real oe_payments_idempotency table.
 */
#[Group('integration')]
final class DoctrineIdempotencyRepositoryClaimTest extends TestCase
{
    private Connection $connection;

    private DoctrineIdempotencyRepository $repository;

    private string $key;

    protected function setUp(): void
    {
        $this->connection = ContainerFactory::getInstance()->getContainer()
            ->get(ConnectionProviderInterface::class)
            ->get();
        $this->repository = new DoctrineIdempotencyRepository($this->connection);
        $this->key = 'admin_payment:refund:it_vr22_' . bin2hex(random_bytes(6)) . ':client:k1';
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement('DELETE FROM oe_payments_idempotency WHERE OXKEY = :k', ['k' => $this->key]);
    }

    public function testAKeyIsClaimedOnceWhileItsRecordLives(): void
    {
        $now = new DateTimeImmutable();

        self::assertTrue($this->repository->claim($this->record($now, '+2 minutes'), $now));
        self::assertFalse($this->repository->claim($this->record($now, '+2 minutes'), $now));
    }

    public function testARecordThatRanOutIsTakenOverOnce(): void
    {
        $now = new DateTimeImmutable();
        $this->repository->claim($this->record($now, '-1 second'), $now);   // released / expired

        self::assertTrue($this->repository->claim($this->record($now, '+2 minutes'), $now));
        self::assertFalse($this->repository->claim($this->record($now, '+2 minutes'), $now), 'the take-over is exclusive');
        self::assertSame('in_flight', $this->repository->findByKey($this->key)?->getStatus());
    }

    private function record(DateTimeImmutable $now, string $expiresIn): IdempotencyRecord
    {
        return new IdempotencyRecord(
            md5($this->key),
            $this->key,
            'it_vr22_order',
            'refund',
            'in_flight',
            $now,
            $now->modify($expiresIn),
        );
    }
}
