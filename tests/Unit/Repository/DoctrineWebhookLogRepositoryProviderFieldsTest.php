<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use OxidEsales\PaymentComponent\Repository\DoctrineWebhookLogRepository;
use OxidEsales\PaymentComponent\Webhook\WebhookLog;
use PHPUnit\Framework\TestCase;

/**
 * TDD Tests for DoctrineWebhookLogRepository provider/payload field support
 *
 * Sprint 2 Phase 1: Repository must persist provider and payload fields
 *
 * @group sprint-2
 * @group webhook-consolidation
 */
class DoctrineWebhookLogRepositoryProviderFieldsTest extends TestCase
{
    /**
     * @test
     * RED: Repository should persist provider field
     */
    public function repositoryShouldPersistProviderField(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('fetchOne')
            ->willReturn(0); // Record doesn't exist

        $connection
            ->expects($this->once())
            ->method('insert')
            ->with(
                'oe_payments_webhooklogs',
                $this->callback(function (array $data) {
                    return isset($data['OXPROVIDER']) && $data['OXPROVIDER'] === 'stripe';
                })
            );

        $repository = new DoctrineWebhookLogRepository($connection);

        $log = new WebhookLog('evt_123', new DateTimeImmutable(), 'received');
        $log->setProvider('stripe');

        $repository->save($log);
    }

    /**
     * @test
     * RED: Repository should persist payload as JSON
     */
    public function repositoryShouldPersistPayloadAsJson(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('fetchOne')
            ->willReturn(0);

        $payload = ['id' => 'pi_123', 'amount' => 1000];

        $connection
            ->expects($this->once())
            ->method('insert')
            ->with(
                'oe_payments_webhooklogs',
                $this->callback(function (array $data) use ($payload) {
                    return isset($data['OXPAYLOAD'])
                        && $data['OXPAYLOAD'] === json_encode($payload);
                })
            );

        $repository = new DoctrineWebhookLogRepository($connection);

        $log = new WebhookLog('evt_456', new DateTimeImmutable(), 'received');
        $log->setPayload($payload);

        $repository->save($log);
    }

    /**
     * @test
     * RED: Repository should persist processedAt field
     */
    public function repositoryShouldPersistProcessedAtField(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('fetchOne')
            ->willReturn(0);

        $processedAt = new DateTimeImmutable('2025-12-02 15:45:00');

        $connection
            ->expects($this->once())
            ->method('insert')
            ->with(
                'oe_payments_webhooklogs',
                $this->callback(function (array $data) {
                    return isset($data['OXPROCESSEDAT'])
                        && $data['OXPROCESSEDAT'] === '2025-12-02 15:45:00';
                })
            );

        $repository = new DoctrineWebhookLogRepository($connection);

        $log = new WebhookLog('evt_789', new DateTimeImmutable(), 'received');
        $log->setProcessedAt($processedAt);

        $repository->save($log);
    }

    /**
     * @test
     * RED: Repository should hydrate provider field from database
     */
    public function repositoryShouldHydrateProviderField(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn([
                'OXID' => 'log_123',
                'OXEVENTID' => 'evt_hydrate_1',
                'OXEVENTTYPE' => 'payment_intent.succeeded',
                'OXSTATUS' => 'processed',
                'OXRECEIVEDAT' => '2025-12-02 15:00:00',
                'OXPROVIDER' => 'stripe',
                'OXPAYLOAD' => '{"id":"pi_123"}',
                'OXPROCESSEDAT' => '2025-12-02 15:01:00',
            ]);

        $repository = new DoctrineWebhookLogRepository($connection);

        $log = $repository->findByEventId('evt_hydrate_1');

        $this->assertNotNull($log);
        $this->assertEquals('stripe', $log->getProvider());
    }

    /**
     * @test
     * RED: Repository should hydrate payload field from database
     */
    public function repositoryShouldHydratePayloadField(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn([
                'OXID' => 'log_456',
                'OXEVENTID' => 'evt_hydrate_2',
                'OXEVENTTYPE' => 'charge.refunded',
                'OXSTATUS' => 'processed',
                'OXRECEIVEDAT' => '2025-12-02 16:00:00',
                'OXPROVIDER' => 'stripe',
                'OXPAYLOAD' => '{"id":"ch_123","amount_refunded":500}',
                'OXPROCESSEDAT' => null,
            ]);

        $repository = new DoctrineWebhookLogRepository($connection);

        $log = $repository->findByEventId('evt_hydrate_2');

        $this->assertNotNull($log);
        $this->assertEquals(['id' => 'ch_123', 'amount_refunded' => 500], $log->getPayload());
    }

    /**
     * @test
     * RED: Repository should hydrate processedAt field from database
     */
    public function repositoryShouldHydrateProcessedAtField(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn([
                'OXID' => 'log_789',
                'OXEVENTID' => 'evt_hydrate_3',
                'OXEVENTTYPE' => 'payment_intent.canceled',
                'OXSTATUS' => 'processed',
                'OXRECEIVEDAT' => '2025-12-02 17:00:00',
                'OXPROVIDER' => 'stripe',
                'OXPAYLOAD' => null,
                'OXPROCESSEDAT' => '2025-12-02 17:05:00',
            ]);

        $repository = new DoctrineWebhookLogRepository($connection);

        $log = $repository->findByEventId('evt_hydrate_3');

        $this->assertNotNull($log);
        $this->assertNotNull($log->getProcessedAt());
        $this->assertEquals('2025-12-02 17:05:00', $log->getProcessedAt()->format('Y-m-d H:i:s'));
    }

    /**
     * @test
     * RED: Repository should handle null provider gracefully
     */
    public function repositoryShouldHandleNullProviderGracefully(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('fetchOne')
            ->willReturn(0);

        // Should allow null provider in insert
        $connection
            ->expects($this->once())
            ->method('insert')
            ->with(
                'oe_payments_webhooklogs',
                $this->callback(function (array $data) {
                    // OXPROVIDER can be null or missing
                    return !isset($data['OXPROVIDER']) || $data['OXPROVIDER'] === null;
                })
            );

        $repository = new DoctrineWebhookLogRepository($connection);

        $log = new WebhookLog('evt_null', new DateTimeImmutable(), 'received');
        // Don't set provider

        $repository->save($log);
    }
}
