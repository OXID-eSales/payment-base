<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OxidEsales\PaymentBase\Logging\ThrowableSummary;
use OxidEsales\PaymentBase\Repository\DoctrineWebhookLogRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A webhook delivery claims its event id once (UNIQUE OXEVENTID). Until this sprint a delivery that
 * ended `failed` kept that claim, so the PSP's retries were answered "Already processed" and the
 * order stayed wherever the failure left it. A failed claim is re-claimable; processed and in-flight
 * claims stay exclusive.
 */
#[CoversClass(DoctrineWebhookLogRepository::class)]
final class DoctrineWebhookLogRepositoryClaimTest extends TestCase
{
    private Connection&MockObject $connection;
    private DoctrineWebhookLogRepository $repository;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->repository = new DoctrineWebhookLogRepository($this->connection, new ThrowableSummary([]));
    }

    public function testAFirstDeliveryClaimsByInserting(): void
    {
        $this->connection->expects(self::once())->method('insert')
            ->with('oe_payments_webhooklogs', self::callback(static fn (array $row) => $row['OXEVENTID'] === 'tr_1:paid' && $row['OXSTATUS'] === 'claimed'))
            ->willReturn(1);
        $this->connection->expects(self::never())->method('update');

        self::assertTrue($this->repository->claimEvent('tr_1:paid', 'mollie', 'paid'));
    }

    public function testARetryReclaimsAFailedOrAStaleClaimedRowInOneGuardedStatement(): void
    {
        $this->connection->method('insert')->willThrowException($this->uniqueViolation());
        $this->connection->expects(self::never())->method('update');
        $this->connection->expects(self::once())->method('executeStatement')
            ->with(
                self::callback(static fn (string $sql): bool => str_contains($sql, 'UPDATE oe_payments_webhooklogs')
                    && str_contains($sql, 'OXSTATUS = :failed OR (OXSTATUS = :claimed AND OXRECEIVEDAT < :staleBefore)')
                    && str_contains($sql, 'OXERROR = NULL')),
                self::callback(static fn (array $params): bool => $params['eventId'] === 'tr_1:paid'
                    && $params['failed'] === 'failed' && $params['claimed'] === 'claimed'),
            )
            ->willReturn(1);

        self::assertTrue($this->repository->claimEvent('tr_1:paid', 'mollie', 'paid'));
    }

    /**
     * VR-19 W1 — a fatal error or a timeout never reaches the processor's catch:
     * the row stays `claimed`. After ten minutes no PHP request is still working
     * on it, so the PSP's next retry takes it over.
     */
    public function testAClaimOlderThanTenMinutesCountsAsStale(): void
    {
        $this->connection->method('insert')->willThrowException($this->uniqueViolation());
        $staleBefore = null;
        $this->connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params) use (&$staleBefore): int {
                $staleBefore = $params['staleBefore'];

                return 1;
            }
        );

        $this->repository->claimEvent('tr_1:paid', 'mollie', 'paid');

        self::assertIsString($staleBefore);
        $age = time() - (new \DateTimeImmutable($staleBefore))->getTimestamp();
        self::assertGreaterThanOrEqual(600, $age);
        self::assertLessThan(660, $age);
    }

    public function testAReplayOfAProcessedOrInFlightDeliveryIsRefused(): void
    {
        $this->connection->method('insert')->willThrowException($this->uniqueViolation());
        $this->connection->method('executeStatement')->willReturn(0);   // processed, or claimed < 10 min ago

        self::assertFalse($this->repository->claimEvent('tr_1:paid', 'mollie', 'paid'));
    }

    private function uniqueViolation(): UniqueConstraintViolationException
    {
        return new UniqueConstraintViolationException($this->createMock(DriverException::class), null);
    }
}
