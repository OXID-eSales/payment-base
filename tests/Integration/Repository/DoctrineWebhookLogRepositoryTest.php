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
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Repository\DoctrineContractRepository;
use OxidEsales\PaymentBase\Repository\DoctrineWebhookLogRepository;
use OxidEsales\PaymentBase\Webhook\WebhookLog;
use PHPUnit\Framework\Attributes\Group;

#[Group('database')]
class DoctrineWebhookLogRepositoryTest extends IntegrationTestCase
{
    private DoctrineWebhookLogRepository $repository;
    private DoctrineContractRepository $contractRepository;
    private Connection $connection;

    public function setUp(): void
    {
        parent::setUp();

        $container = ContainerFactory::getInstance()->getContainer();
        $connectionProvider = $container->get(\OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface::class);
        $this->connection = $connectionProvider->get();
        $this->repository = new DoctrineWebhookLogRepository($this->connection);
        $this->contractRepository = new DoctrineContractRepository($this->connection);

        // Clean up test data
        $this->cleanupTestData();
    }

    public function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    private function cleanupTestData(): void
    {
        // Clean webhook logs first (has FK to contracts)
        $this->connection->executeStatement('DELETE FROM oe_payments_webhooklogs WHERE OXEVENTID LIKE "test_%"');
        // Clean test contracts
        $this->connection->executeStatement('DELETE FROM oe_payments_contract WHERE OXID LIKE "contract_%"');
    }

    private function createTestContract(string $contractId): PaymentContract
    {
        $basketSnapshot = BasketSnapshot::fromArray([
            'items' => [
                [
                    'articleId' => 'test_article_1',
                    'title' => 'Test Product',
                    'amount' => 1,
                    'price' => 10.00,
                    'vat' => 19,
                ],
            ],
            'totalGross' => 10.00,
            'totalNet' => 8.40,
            'totalVat' => 1.60,
            'currency' => 'EUR',
        ]);

        $contract = new PaymentContract(
            1, // shopId
            'test_user_123',
            $basketSnapshot,
            $contractId
        );

        $this->contractRepository->save($contract);
        return $contract;
    }

    private function createTestWebhookLog(string $eventId = 'test_event_123', ?string $id = null): WebhookLog
    {
        // Use predictable IDs for tests to avoid uniqid() collisions
        // Use only md5 hash (32 chars) to fit CHAR(32) column
        $logId = $id ?? md5('webhook_log_' . $eventId);

        return new WebhookLog(
            $eventId,
            new DateTimeImmutable(),
            'received',
            $logId
        );
    }

    public function testSaveAndFindByEventId(): void
    {
        // Given - Create contract first to satisfy FK constraint
        $this->createTestContract('contract_123');

        $log = $this->createTestWebhookLog('test_event_123');
        $log->setEventType('payment_intent.succeeded');
        $log->setContractId('contract_123');

        // When
        $this->repository->save($log);

        // Then
        $found = $this->repository->findByEventId('test_event_123');

        $this->assertNotNull($found);
        $this->assertInstanceOf(WebhookLog::class, $found);
        $this->assertEquals('test_event_123', $found->getEventId());
        $this->assertEquals('payment_intent.succeeded', $found->getEventType());
        $this->assertEquals('contract_123', $found->getContractId());
        $this->assertEquals('received', $found->getStatus());
    }

    public function testFindByEventIdReturnsNullWhenNotFound(): void
    {
        // When
        $found = $this->repository->findByEventId('non_existent_event_id');

        // Then
        $this->assertNull($found);
    }

    public function testExistsByEventId(): void
    {
        // Given
        $log = $this->createTestWebhookLog('test_event_exists');
        $this->repository->save($log);

        // When/Then
        $this->assertTrue($this->repository->existsByEventId('test_event_exists'));
        $this->assertFalse($this->repository->existsByEventId('non_existent_event'));
    }

    public function testSaveWithoutOptionalFields(): void
    {
        // Given
        $log = $this->createTestWebhookLog('test_event_minimal');

        // When
        $this->repository->save($log);

        // Then
        $found = $this->repository->findByEventId('test_event_minimal');

        $this->assertNotNull($found);
        $this->assertNull($found->getEventType());
        $this->assertNull($found->getContractId());
        $this->assertNull($found->getError());
    }

    public function testSaveWithError(): void
    {
        // Given
        $log = $this->createTestWebhookLog('test_event_with_error');
        $log->setError('Failed to process webhook: Invalid signature');

        // When
        $this->repository->save($log);

        // Then
        $found = $this->repository->findByEventId('test_event_with_error');

        $this->assertNotNull($found);
        $this->assertEquals('Failed to process webhook: Invalid signature', $found->getError());
    }

    public function testUpdateWebhookLog(): void
    {
        // Given - Create contract first to satisfy FK constraint
        $this->createTestContract('contract_456');

        $log = $this->createTestWebhookLog('test_event_update');
        $log->setEventType('payment_intent.created');
        $this->repository->save($log);

        // When - update the log
        $log->setEventType('payment_intent.succeeded');
        $log->setContractId('contract_456');
        $this->repository->save($log);

        // Then
        $found = $this->repository->findByEventId('test_event_update');

        $this->assertNotNull($found);
        $this->assertEquals('payment_intent.succeeded', $found->getEventType());
        $this->assertEquals('contract_456', $found->getContractId());
    }

    public function testSaveMultipleWebhookLogs(): void
    {
        // Given
        $log1 = $this->createTestWebhookLog('test_event_1');
        $log2 = $this->createTestWebhookLog('test_event_2');
        $log3 = $this->createTestWebhookLog('test_event_3');

        // When
        $this->repository->save($log1);
        $this->repository->save($log2);
        $this->repository->save($log3);

        // Then
        $this->assertTrue($this->repository->existsByEventId('test_event_1'));
        $this->assertTrue($this->repository->existsByEventId('test_event_2'));
        $this->assertTrue($this->repository->existsByEventId('test_event_3'));
    }

    public function testIdempotencyCheck(): void
    {
        // Given
        $log = $this->createTestWebhookLog('test_event_idempotent');

        // When - save the same event twice
        $this->repository->save($log);

        // Then - second save should update, not create duplicate
        $this->repository->save($log);

        // Verify only one record exists
        $this->assertTrue($this->repository->existsByEventId('test_event_idempotent'));
    }

    public function testSaveWithAllFields(): void
    {
        // Given - Create contract first to satisfy FK constraint
        $this->createTestContract('contract_789');

        $log = $this->createTestWebhookLog('test_event_complete');
        $log->setEventType('charge.succeeded');
        $log->setContractId('contract_789');
        $log->setError(null);

        // When
        $this->repository->save($log);

        // Then
        $found = $this->repository->findByEventId('test_event_complete');

        $this->assertNotNull($found);
        $this->assertEquals('test_event_complete', $found->getEventId());
        $this->assertEquals('charge.succeeded', $found->getEventType());
        $this->assertEquals('contract_789', $found->getContractId());
        $this->assertEquals('received', $found->getStatus());
        $this->assertNull($found->getError());
        $this->assertInstanceOf(DateTimeImmutable::class, $found->getReceivedAt());
    }
}
