<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Contract;

use Doctrine\DBAL\Connection;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\DoctrineContractRepository;

/**
 * Sprint 8: Contract Capture/Refund Tracking Tests
 *
 * TDD tests for new contract fields:
 * - OXCAPTUREDAMOUNT
 * - OXREFUNDEDAMOUNT
 * - OXCAPTUREDAT
 * - OXREFUNDEDAT
 *
 * @covers \OxidEsales\PaymentBase\Contract\PaymentContract
 * @covers \OxidEsales\PaymentBase\Repository\DoctrineContractRepository
 * @group integration
 * @group contract
 * @group sprint-8
 */
final class ContractCaptureRefundTest extends IntegrationTestCase
{
    private const TEST_PREFIX = 'capref_test_';
    private const SHOP_ID = 1;

    private Connection $connection;
    private ContractRepositoryInterface $contractRepository;
    private string $testRunId;

    public function setUp(): void
    {
        parent::setUp();

        $this->testRunId = date('His') . '_' . substr(uniqid(), -4);

        $container = ContainerFactory::getInstance()->getContainer();
        /** @var ConnectionProviderInterface $connectionProvider */
        $connectionProvider = $container->get(ConnectionProviderInterface::class);
        $this->connection = $connectionProvider->get();

        // Direct instantiation to work in CI without module activation
        $this->contractRepository = new DoctrineContractRepository($this->connection);
    }

    public function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    /**
     * @test
     */
    public function contractStoresCapturedAmount(): void
    {
        // Given: A committed contract
        $contract = $this->createTestContract();
        $this->transitionToCommitted($contract);
        $this->contractRepository->save($contract);

        // When: Set captured amount
        $contract->setCapturedAmount(99.99);
        $contract->setCapturedAt(new \DateTimeImmutable());
        $this->contractRepository->save($contract);

        // Then: Amount is persisted
        $loaded = $this->contractRepository->findById($contract->getId());
        $this->assertNotNull($loaded);
        $this->assertEquals(99.99, $loaded->getCapturedAmount());
        $this->assertNotNull($loaded->getCapturedAt());
    }

    /**
     * @test
     */
    public function contractStoresRefundedAmount(): void
    {
        // Given: A fulfilled contract
        $contract = $this->createTestContract();
        $this->transitionToFulfilled($contract);
        $contract->setCapturedAmount(100.00);
        $this->contractRepository->save($contract);

        // When: Process refund
        $contract->addRefundedAmount(25.00);
        $contract->setRefundedAt(new \DateTimeImmutable());
        $this->contractRepository->save($contract);

        // Then: Refund amount is persisted
        $loaded = $this->contractRepository->findById($contract->getId());
        $this->assertNotNull($loaded);
        $this->assertEquals(25.00, $loaded->getRefundedAmount());
        $this->assertNotNull($loaded->getRefundedAt());
    }

    /**
     * @test
     */
    public function multipleRefundsAccumulate(): void
    {
        // Given: A fulfilled contract with existing refund
        $contract = $this->createTestContract();
        $this->transitionToFulfilled($contract);
        $contract->setCapturedAmount(100.00);
        $contract->addRefundedAmount(20.00);
        $this->contractRepository->save($contract);

        // When: Second refund processed
        $loaded = $this->contractRepository->findById($contract->getId());
        $this->assertNotNull($loaded);
        $loaded->addRefundedAmount(30.00);
        $loaded->setRefundedAt(new \DateTimeImmutable());
        $this->contractRepository->save($loaded);

        // Then: Refunds are accumulated
        $final = $this->contractRepository->findById($contract->getId());
        $this->assertNotNull($final);
        $this->assertEquals(50.00, $final->getRefundedAmount());
    }

    /**
     * @test
     */
    public function contractWithNullAmountsLoadsCorrectly(): void
    {
        // Given: A new contract without capture/refund data
        $contract = $this->createTestContract();
        $this->contractRepository->save($contract);

        // When: Load contract
        $loaded = $this->contractRepository->findById($contract->getId());

        // Then: Amounts are null
        $this->assertNotNull($loaded);
        $this->assertNull($loaded->getCapturedAmount());
        $this->assertNull($loaded->getRefundedAmount());
        $this->assertNull($loaded->getCapturedAt());
        $this->assertNull($loaded->getRefundedAt());
    }

    /**
     * @test
     */
    public function partialRefundDoesNotExceedCaptured(): void
    {
        // Given: A fulfilled and captured contract
        $contract = $this->createTestContract();
        $this->transitionToFulfilled($contract);
        $contract->setCapturedAmount(100.00);
        $this->contractRepository->save($contract);

        // When: Refund partial amount
        $contract->addRefundedAmount(40.00);
        $this->contractRepository->save($contract);

        // Then: Remaining can be calculated
        $loaded = $this->contractRepository->findById($contract->getId());
        $this->assertNotNull($loaded);

        $captured = $loaded->getCapturedAmount() ?? 0;
        $refunded = $loaded->getRefundedAmount() ?? 0;
        $remaining = $captured - $refunded;

        $this->assertEquals(60.00, $remaining);
    }

    private function createTestContract(): PaymentContract
    {
        $basketSnapshot = BasketSnapshot::fromArray([
            'items' => [],
            'totalGross' => 100.00,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
        ]);

        $contractId = self::TEST_PREFIX . $this->testRunId . '_' . substr(uniqid(), -4);

        return new PaymentContract(
            self::SHOP_ID,
            'oxdefaultadmin',
            $basketSnapshot,
            $contractId
        );
    }

    /**
     * Transition contract to COMMITTED state (required for setCapturedAmount).
     */
    private function transitionToCommitted(PaymentContract $contract): void
    {
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_' . substr(uniqid(), -4));
        $contract->transitionToPending();
        $contract->setProvider('test', 'pi_' . substr(uniqid(), -4));
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED, ['authId' => 'auth_test']);
        $contract->commitToOrder('order_' . substr(uniqid(), -4));
    }

    /**
     * Transition contract to FULFILLED state (required for addRefundedAmount).
     */
    private function transitionToFulfilled(PaymentContract $contract): void
    {
        $this->transitionToCommitted($contract);
        $contract->fulfill();
    }

    private function cleanupTestData(): void
    {
        $this->connection->executeStatement(
            "DELETE FROM oe_payments_contract WHERE OXID LIKE ?",
            [self::TEST_PREFIX . '%']
        );
    }
}
