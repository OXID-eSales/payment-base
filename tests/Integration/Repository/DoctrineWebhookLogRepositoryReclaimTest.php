<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Repository;

use Doctrine\DBAL\Connection;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface;
use OxidEsales\PaymentBase\Logging\ThrowableSummary;
use OxidEsales\PaymentBase\Repository\DoctrineWebhookLogRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * VR-19 W1 — the re-claim statement against the real table: a `failed` row and
 * a `claimed` row older than ten minutes are taken over by the PSP's retry;
 * a fresh claim and a processed row are not.
 */
#[Group('integration')]
final class DoctrineWebhookLogRepositoryReclaimTest extends TestCase
{
    private Connection $connection;

    private DoctrineWebhookLogRepository $repository;

    private string $eventId;

    protected function setUp(): void
    {
        $this->connection = ContainerFactory::getInstance()->getContainer()
            ->get(ConnectionProviderInterface::class)
            ->get();
        $this->repository = new DoctrineWebhookLogRepository($this->connection, new ThrowableSummary([]));
        $this->eventId = 'it_vr19_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM oe_payments_webhooklogs WHERE OXEVENTID = :id',
            ['id' => $this->eventId]
        );
    }

    public function testAFreshClaimIsExclusive(): void
    {
        self::assertTrue($this->repository->claimEvent($this->eventId, 'test', 'x'));
        self::assertFalse($this->repository->claimEvent($this->eventId, 'test', 'x'));
    }

    public function testAClaimLeftByADeadProcessIsTakenOverAfterTenMinutes(): void
    {
        $this->repository->claimEvent($this->eventId, 'test', 'x');
        $this->ageClaim(minutes: 11);

        self::assertTrue($this->repository->claimEvent($this->eventId, 'test', 'x'));
        self::assertFalse($this->repository->claimEvent($this->eventId, 'test', 'x'), 'the take-over is a fresh claim');
    }

    public function testAClaimYoungerThanTenMinutesIsStillInFlight(): void
    {
        $this->repository->claimEvent($this->eventId, 'test', 'x');
        $this->ageClaim(minutes: 9);

        self::assertFalse($this->repository->claimEvent($this->eventId, 'test', 'x'));
    }

    public function testAFailedDeliveryIsReclaimedAndAProcessedOneIsNot(): void
    {
        $this->repository->claimEvent($this->eventId, 'test', 'x');
        $this->repository->updateStatus($this->eventId, 'failed', 'boom', null);
        self::assertTrue($this->repository->claimEvent($this->eventId, 'test', 'x'));

        $this->repository->updateStatus($this->eventId, 'processed', null, null);
        self::assertFalse($this->repository->claimEvent($this->eventId, 'test', 'x'));
    }

    private function ageClaim(int $minutes): void
    {
        $this->connection->executeStatement(
            'UPDATE oe_payments_webhooklogs SET OXRECEIVEDAT = :at WHERE OXEVENTID = :id',
            [
                'at' => (new \DateTimeImmutable())->modify('-' . $minutes . ' minutes')->format('Y-m-d H:i:s'),
                'id' => $this->eventId,
            ]
        );
    }
}
