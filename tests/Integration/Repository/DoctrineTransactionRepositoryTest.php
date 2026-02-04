<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Integration\Repository;

use Doctrine\DBAL\Connection;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\PaymentComponent\Repository\TransactionRepository;
use OxidEsales\PaymentComponent\Transaction\Transaction;

/**
 * @group database
 */
class DoctrineTransactionRepositoryTest extends IntegrationTestCase
{
    private TransactionRepository $repository;
    private Connection $connection;

    public function setUp(): void
    {
        parent::setUp();

        $container = ContainerFactory::getInstance()->getContainer();
        $connectionProvider = $container->get(\OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface::class);
        $this->connection = $connectionProvider->get();
        $this->repository = new TransactionRepository($this->connection);

        $this->cleanupTestData();
        $this->createTestContracts();
    }

    public function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    private function cleanupTestData(): void
    {
        $this->connection->executeStatement('DELETE FROM oe_payments_transaction WHERE OXID LIKE "test_%"');
        $this->connection->executeStatement('DELETE FROM oe_payments_contract WHERE OXID LIKE "test_%"');
    }

    private function createTestContracts(): void
    {
        // Create test contracts that transactions can reference
        $contracts = [
            'test_contract_123' => ['order' => 'test_order_123', 'user' => 'test_user_123'],
            'test_contract_456' => ['order' => 'test_order_456', 'user' => 'test_user_456'],
        ];

        foreach ($contracts as $contractId => $data) {
            $this->connection->insert('oe_payments_contract', [
                'OXID' => $contractId,
                'OXSHOPID' => 1,
                'OXUSERID' => $data['user'],
                'OXORDERID' => $data['order'],
                'OXSTATE' => 'committed',
                'OXBASKETDATA' => json_encode(['items' => []]),
                'OXCONDITIONS' => json_encode([]),
                'OXPROVIDER' => 'stripe',
                'OXCREATED' => date('Y-m-d H:i:s'),
                'OXUPDATED' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function createTestTransaction(string $id = 'test_tx_123'): Transaction
    {
        return new Transaction(
            $id,
            1, // shopId
            'test_order_123',
            'test_contract_123',
            'stripe',
            'authorization',
            'pending',
            99.99,
            'EUR'
        );
    }

    public function testSaveAndFindById(): void
    {
        // Given
        $transaction = $this->createTestTransaction();
        $transaction->setProviderOrderId('pi_test_123');
        $transaction->setTransactionId('txn_test_123');

        // When
        $this->repository->save($transaction);

        // Then
        $found = $this->repository->findById('test_tx_123');

        $this->assertNotNull($found);
        $this->assertInstanceOf(Transaction::class, $found);
        $this->assertEquals('test_tx_123', $found->getId());
        $this->assertEquals('test_order_123', $found->getOrderId());
        $this->assertEquals('test_contract_123', $found->getContractId());
        $this->assertEquals('stripe', $found->getProvider());
        $this->assertEquals('authorization', $found->getType());
        $this->assertEquals('pending', $found->getStatus());
        $this->assertEquals(99.99, $found->getAmount());
        $this->assertEquals('EUR', $found->getCurrency());
        $this->assertEquals('pi_test_123', $found->getProviderOrderId());
        $this->assertEquals('txn_test_123', $found->getTransactionId());
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        // When
        $found = $this->repository->findById('non_existent_id');

        // Then
        $this->assertNull($found);
    }

    public function testUpdateTransaction(): void
    {
        // Given
        $transaction = $this->createTestTransaction();
        $transaction->setStatus('pending');
        $this->repository->save($transaction);

        // When - update status
        $transaction->setStatus('completed');
        $this->repository->save($transaction);

        // Then
        $found = $this->repository->findById('test_tx_123');

        $this->assertNotNull($found);
        $this->assertEquals('completed', $found->getStatus());
    }

    public function testFindByOrderId(): void
    {
        // Given
        $tx1 = $this->createTestTransaction('test_tx_1');
        $tx2 = $this->createTestTransaction('test_tx_2');
        $tx3 = new Transaction(
            'test_tx_3',
            1,
            'test_order_456',
            null,
            'paypal',
            'capture',
            'completed',
            50.00,
            'USD'
        );

        $this->repository->save($tx1);
        $this->repository->save($tx2);
        $this->repository->save($tx3);

        // When
        $found = $this->repository->findByOrderId('test_order_123');

        // Then
        $this->assertIsArray($found);
        $this->assertCount(2, $found);
        $this->assertContainsOnlyInstancesOf(Transaction::class, $found);
    }

    public function testFindByContractId(): void
    {
        // Given
        $tx1 = $this->createTestTransaction('test_tx_1');
        $tx2 = $this->createTestTransaction('test_tx_2');
        $tx3 = new Transaction(
            'test_tx_3',
            1,
            'test_order_456',
            'test_contract_456',
            'stripe',
            'capture',
            'completed',
            50.00,
            'EUR'
        );

        $this->repository->save($tx1);
        $this->repository->save($tx2);
        $this->repository->save($tx3);

        // When
        $found = $this->repository->findByContractId('test_contract_123');

        // Then
        $this->assertIsArray($found);
        $this->assertCount(2, $found);
        $this->assertEquals('test_tx_1', $found[0]->getId());
        $this->assertEquals('test_tx_2', $found[1]->getId());
    }

    public function testFindByProviderTransactionId(): void
    {
        // Given
        $transaction = $this->createTestTransaction();
        $transaction->setTransactionId('txn_unique_123');
        $this->repository->save($transaction);

        // When
        $found = $this->repository->findByProviderTransactionId('txn_unique_123');

        // Then
        $this->assertNotNull($found);
        $this->assertEquals('test_tx_123', $found->getId());
        $this->assertEquals('txn_unique_123', $found->getTransactionId());
    }

    public function testFindByTypeAndStatus(): void
    {
        // Given
        $tx1 = new Transaction('test_tx_auth_pending', 1, 'order1', null, 'stripe', 'authorization', 'pending', 100.00, 'EUR');
        $tx2 = new Transaction('test_tx_auth_completed', 1, 'order2', null, 'stripe', 'authorization', 'completed', 200.00, 'EUR');
        $tx3 = new Transaction('test_tx_capture_pending', 1, 'order3', null, 'stripe', 'capture', 'pending', 150.00, 'EUR');

        $this->repository->save($tx1);
        $this->repository->save($tx2);
        $this->repository->save($tx3);

        // When
        $found = $this->repository->findByTypeAndStatus('authorization', 'pending');

        // Then
        $this->assertIsArray($found);
        $this->assertCount(1, $found);
        $this->assertEquals('test_tx_auth_pending', $found[0]->getId());
    }

    public function testFindChildTransactions(): void
    {
        // Given - parent authorization transaction
        $parent = $this->createTestTransaction('test_tx_parent');
        $this->repository->save($parent);

        // Given - child refund transactions
        $refund1 = new Transaction('test_tx_refund1', 1, 'test_order_123', null, 'stripe', 'refund', 'completed', 25.00, 'EUR');
        $refund1->setParentTransactionId('test_tx_parent');

        $refund2 = new Transaction('test_tx_refund2', 1, 'test_order_123', null, 'stripe', 'refund', 'completed', 30.00, 'EUR');
        $refund2->setParentTransactionId('test_tx_parent');

        $this->repository->save($refund1);
        $this->repository->save($refund2);

        // When
        $children = $this->repository->findChildTransactions('test_tx_parent');

        // Then
        $this->assertIsArray($children);
        $this->assertCount(2, $children);
        $this->assertEquals('test_tx_refund1', $children[0]->getId());
        $this->assertEquals('test_tx_refund2', $children[1]->getId());
    }

    public function testExists(): void
    {
        // Given
        $transaction = $this->createTestTransaction();
        $this->repository->save($transaction);

        // When/Then
        $this->assertTrue($this->repository->exists('test_tx_123'));
        $this->assertFalse($this->repository->exists('non_existent_id'));
    }

    public function testSaveWithNullContractId(): void
    {
        // Given
        $transaction = new Transaction(
            'test_tx_no_contract',
            1,
            'test_order_999',
            null, // no contract
            'paypal',
            'capture',
            'completed',
            75.50,
            'USD'
        );

        // When
        $this->repository->save($transaction);

        // Then
        $found = $this->repository->findById('test_tx_no_contract');

        $this->assertNotNull($found);
        $this->assertNull($found->getContractId());
    }

    public function testSaveWithAllOptionalFields(): void
    {
        // Given - create parent transaction first to satisfy FK constraint
        $parentTransaction = $this->createTestTransaction('test_parent_tx_123');
        $this->repository->save($parentTransaction);

        // Given - create child transaction with all optional fields
        $transaction = $this->createTestTransaction();
        $transaction->setProviderOrderId('pi_complete_123');
        $transaction->setTransactionId('txn_complete_123');
        $transaction->setPaymentMethodId('pm_card_visa');
        $transaction->setPaymentMethodType('card');
        $transaction->setParentTransactionId('test_parent_tx_123');

        // When
        $this->repository->save($transaction);

        // Then
        $found = $this->repository->findById('test_tx_123');

        $this->assertNotNull($found);
        $this->assertEquals('pi_complete_123', $found->getProviderOrderId());
        $this->assertEquals('txn_complete_123', $found->getTransactionId());
        $this->assertEquals('pm_card_visa', $found->getPaymentMethodId());
        $this->assertEquals('card', $found->getPaymentMethodType());
        $this->assertEquals('test_parent_tx_123', $found->getParentTransactionId());
    }

    public function testMultipleSavesUpdate(): void
    {
        // Given
        $transaction = $this->createTestTransaction();
        $transaction->setStatus('pending');
        $this->repository->save($transaction);

        // When - multiple updates
        $transaction->setStatus('processing');
        $this->repository->save($transaction);

        $transaction->setStatus('completed');
        $transaction->setTransactionId('txn_final_123');
        $this->repository->save($transaction);

        // Then
        $found = $this->repository->findById('test_tx_123');

        $this->assertNotNull($found);
        $this->assertEquals('completed', $found->getStatus());
        $this->assertEquals('txn_final_123', $found->getTransactionId());
    }
}
