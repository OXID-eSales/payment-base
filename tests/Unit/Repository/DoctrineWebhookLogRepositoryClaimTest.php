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
        $this->repository = new DoctrineWebhookLogRepository($this->connection);
    }

    public function testAFirstDeliveryClaimsByInserting(): void
    {
        $this->connection->expects(self::once())->method('insert')
            ->with('oe_payments_webhooklogs', self::callback(static fn (array $row) => $row['OXEVENTID'] === 'tr_1:paid' && $row['OXSTATUS'] === 'claimed'))
            ->willReturn(1);
        $this->connection->expects(self::never())->method('update');

        self::assertTrue($this->repository->claimEvent('tr_1:paid', 'mollie', 'paid'));
    }

    public function testARetryAfterAFailedDeliveryReclaims(): void
    {
        $this->connection->method('insert')->willThrowException($this->uniqueViolation());
        $this->connection->expects(self::once())->method('update')
            ->with(
                'oe_payments_webhooklogs',
                self::callback(static fn (array $data) => $data['OXSTATUS'] === 'claimed' && array_key_exists('OXERROR', $data) && $data['OXERROR'] === null),
                ['OXEVENTID' => 'tr_1:paid', 'OXSTATUS' => 'failed'],
            )
            ->willReturn(1);

        self::assertTrue($this->repository->claimEvent('tr_1:paid', 'mollie', 'paid'));
    }

    public function testAReplayOfAProcessedDeliveryIsRefused(): void
    {
        $this->connection->method('insert')->willThrowException($this->uniqueViolation());
        $this->connection->method('update')->willReturn(0);   // the row is not `failed`

        self::assertFalse($this->repository->claimEvent('tr_1:paid', 'mollie', 'paid'));
    }

    private function uniqueViolation(): UniqueConstraintViolationException
    {
        return new UniqueConstraintViolationException($this->createMock(DriverException::class), null);
    }
}
