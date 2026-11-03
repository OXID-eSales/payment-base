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
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\PaymentBase\Repository\DoctrineWebhookLogRepository;
use PHPUnit\Framework\Attributes\Group;

/**
 * The two operations the webhook processing uses (VR-17 / VR-24 DC-PB7b): claim a delivery, record its outcome.
 */
#[Group('database')]
class DoctrineWebhookLogRepositoryTest extends IntegrationTestCase
{
    private DoctrineWebhookLogRepository $repository;
    private Connection $connection;

    public function setUp(): void
    {
        parent::setUp();

        $container = ContainerFactory::getInstance()->getContainer();
        $this->connection = $container->get(ConnectionProviderInterface::class)->get();
        $this->repository = new DoctrineWebhookLogRepository($this->connection);
        $this->cleanupTestData();
    }

    public function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    private function cleanupTestData(): void
    {
        $this->connection->executeStatement('DELETE FROM oe_payments_webhooklogs WHERE OXEVENTID LIKE "test_%"');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $eventId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM oe_payments_webhooklogs WHERE OXEVENTID = :eventId',
            ['eventId' => $eventId]
        );
        self::assertIsArray($row);

        return $row;
    }

    public function testClaimEvent_FirstDelivery_ClaimsAndRecordsProviderAndType(): void
    {
        self::assertTrue($this->repository->claimEvent('test_event_1', 'acme', 'payment.paid'));

        $row = $this->row('test_event_1');
        self::assertSame('claimed', $row['OXSTATUS']);
        self::assertSame('acme', $row['OXPROVIDER']);
        self::assertSame('payment.paid', $row['OXEVENTTYPE']);
    }

    public function testClaimEvent_SecondDeliveryWhileClaimed_IsRefused(): void
    {
        $this->repository->claimEvent('test_event_2', 'acme', 'payment.paid');

        self::assertFalse($this->repository->claimEvent('test_event_2', 'acme', 'payment.paid'));
    }

    public function testUpdateStatus_Failed_AllowsTheRetryToClaimAgain(): void
    {
        $this->repository->claimEvent('test_event_3', 'acme', 'payment.paid');
        $this->repository->updateStatus('test_event_3', 'failed', 'boom');

        $row = $this->row('test_event_3');
        self::assertSame('failed', $row['OXSTATUS']);
        self::assertSame('boom', $row['OXERROR']);
        self::assertNotNull($row['OXPROCESSEDAT']);
        self::assertTrue($this->repository->claimEvent('test_event_3', 'acme', 'payment.paid'));
    }

    public function testUpdateStatus_Processed_StaysExclusive(): void
    {
        $this->repository->claimEvent('test_event_4', 'acme', 'payment.paid');
        $this->repository->updateStatus('test_event_4', 'processed');

        self::assertSame('processed', $this->row('test_event_4')['OXSTATUS']);
        self::assertFalse($this->repository->claimEvent('test_event_4', 'acme', 'payment.paid'));
    }
}
