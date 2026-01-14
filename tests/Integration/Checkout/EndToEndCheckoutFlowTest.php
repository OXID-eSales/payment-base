<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Integration\Checkout;

use DateTime;
use Doctrine\DBAL\Connection;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\Contract\ContractCondition;
use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractCreatedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\PaymentInitiatedEvent;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcher;
use OxidEsales\PaymentComponent\EventSystem\EventListenerProvider;
use OxidEsales\PaymentComponent\EventSystem\Handler\ContractCreationHandler;
use OxidEsales\PaymentComponent\Repository\DoctrineContractRepository;
use OxidEsales\PaymentComponent\Service\CheckoutOrchestrator;
use OxidEsales\PaymentComponent\Service\ContractService;
use OxidEsales\PaymentComponent\Service\Result\CheckoutResult;
use OxidEsales\PaymentComponent\Service\Result\OrderConfirmationResult;
use Psr\Log\NullLogger;

/**
 * End-to-end integration test for the complete checkout flow.
 *
 * Tests the entire flow from OrderController.execute() to ThankyouController,
 * using REAL database connection and leaving data in the database for inspection.
 *
 * Key features:
 * - Uses real DoctrineContractRepository with actual MySQL connection
 * - Data is persisted to osc_payment_contract table
 * - Data is NOT cleaned up after tests (for manual inspection)
 * - All test contract IDs start with "e2e_test_" prefix
 *
 * Note: This test does NOT test Stripe API integration (webhooks, idempotency).
 * It tests the internal contract and order state machine.
 *
 * @group integration
 * @group checkout
 * @group e2e
 * @group database
 */
final class EndToEndCheckoutFlowTest extends IntegrationTestCase
{
    private const TEST_PREFIX = 'e2e_';

    private EventDispatcher $eventDispatcher;
    private CheckoutOrchestrator $orchestrator;
    private DoctrineContractRepository $contractRepository;
    private ContractService $contractService;
    private Connection $connection;

    /**
     * Test run identifier - unique per test run to allow tracking
     * Format: HHMMSS_XXXX (10 chars) to keep total ID under 32 chars
     */
    private string $testRunId;

    public function setUp(): void
    {
        parent::setUp();

        // Generate short unique test run ID (10 chars: HHMMSS_XXXX)
        $this->testRunId = date('His') . '_' . substr(uniqid(), -4);

        // Get real database connection from OXID DI container
        $container = ContainerFactory::getInstance()->getContainer();
        /** @var ConnectionProviderInterface $connectionProvider */
        $connectionProvider = $container->get(ConnectionProviderInterface::class);
        $this->connection = $connectionProvider->get();

        // Create real DoctrineContractRepository with database connection
        $this->contractRepository = new DoctrineContractRepository($this->connection);

        // Create ContractService with real repository
        $this->contractService = new ContractService($this->contractRepository);

        // Create EventListenerProvider with empty handlers initially
        $listenerProvider = new EventListenerProvider([]);

        // Create EventDispatcher
        $this->eventDispatcher = new EventDispatcher($listenerProvider);

        // Wire up ContractCreationHandler to handle PaymentInitiatedEvent
        $contractCreationHandler = new ContractCreationHandler(
            $this->contractService,
            $this->eventDispatcher
        );

        // Register handler for PaymentInitiatedEvent
        $this->eventDispatcher->addListener(
            PaymentInitiatedEvent::class,
            fn($event) => $contractCreationHandler->handle($event)
        );

        // Create CheckoutOrchestrator with the dispatcher
        $this->orchestrator = new CheckoutOrchestrator(
            $this->eventDispatcher,
            new NullLogger()
        );
    }

    /**
     * NOTE: tearDown intentionally does NOT roll back transaction.
     * Data remains in the database for manual inspection.
     * All test data has "e2e_" prefix for easy identification.
     *
     * To query test data:
     * SELECT * FROM osc_payment_contract WHERE OXID LIKE 'e2e_%' OR OXUSERID LIKE 'e2e_%';
     */
    public function tearDown(): void
    {
        // Commit transaction instead of rollback - data stays in DB
        $this->commitTransaction();

        // Call parent but skip the rollback (it's already committed)
        $this->cleanupCaching();
        $this->restoreRequestData();
    }

    /**
     * Commit transaction to persist test data.
     */
    private function commitTransaction(): void
    {
        $container = ContainerFactory::getInstance()->getContainer();
        /** @var ConnectionProviderInterface $connectionProvider */
        $connectionProvider = $container->get(ConnectionProviderInterface::class);
        $connection = $connectionProvider->get();

        if ($connection->isTransactionActive()) {
            $connection->commit();
        }
    }

    // =========================================================================
    // PHASE 1: Contract Creation (OrderController.execute())
    // =========================================================================

    /**
     * @group integration
     * @group e2e
     * @group database
     */
    public function testFullCheckoutFlow_CreatesContractInDatabase(): void
    {
        $basket = $this->createValidBasket(150.00, 'EUR');
        $user = $this->createValidUser($this->generateTestUserId('user_create'));

        $result = $this->orchestrator->processCheckout(
            $basket,
            $user,
            'stripe_card',
            'pi_test_intent_' . $this->testRunId
        );

        $this->assertInstanceOf(CheckoutResult::class, $result);

        // Debug: if checkout failed, show the error message
        if (!$result->isSuccess()) {
            $this->fail(sprintf(
                'Checkout failed: %s (code: %s)',
                $result->getErrorMessage() ?? 'no message',
                $result->getErrorCode() ?? 'no code'
            ));
        }

        $this->assertNotNull($result->getContractId(), 'Contract ID should be set');

        // Verify contract was persisted to real database
        $contractId = $result->getContractId();
        $this->assertIsString($contractId);

        // Query database directly to verify persistence
        $dbRow = $this->connection->fetchAssociative(
            'SELECT * FROM osc_payment_contract WHERE OXID = :id',
            ['id' => $contractId]
        );

        $this->assertNotFalse($dbRow, 'Contract should be persisted in database');
        $this->assertEquals($contractId, $dbRow['OXID']);
        $this->assertEquals('draft', $dbRow['OXSTATE']);
    }

    /**
     * @group integration
     * @group e2e
     * @group database
     */
    public function testFullCheckoutFlow_ContractHasCorrectBasketSnapshot(): void
    {
        $basket = $this->createValidBasket(199.99, 'USD');
        $user = $this->createValidUser($this->generateTestUserId('user_basket'));

        $result = $this->orchestrator->processCheckout(
            $basket,
            $user,
            'stripe_sepa',
            null
        );

        $this->assertTrue($result->isSuccess());
        $contractId = $result->getContractId();
        $this->assertNotNull($contractId);

        $contract = $this->contractRepository->findById($contractId);
        $this->assertNotNull($contract);

        $snapshot = $contract->getBasketSnapshot();
        $this->assertEquals(199.99, $snapshot->getTotalGross());
        $this->assertEquals('USD', $snapshot->getCurrency());

        // Verify in database
        $dbRow = $this->connection->fetchAssociative(
            'SELECT OXBASKETDATA FROM osc_payment_contract WHERE OXID = :id',
            ['id' => $contractId]
        );
        $basketData = json_decode($dbRow['OXBASKETDATA'], true);
        $this->assertEquals(199.99, $basketData['totalGross']);
        $this->assertEquals('USD', $basketData['currency']);
    }

    /**
     * @group integration
     * @group e2e
     * @group database
     */
    public function testFullCheckoutFlow_ContractHasDefaultConditions(): void
    {
        $basket = $this->createValidBasket(100.00, 'EUR');
        $user = $this->createValidUser($this->generateTestUserId('user_conditions'));

        $result = $this->orchestrator->processCheckout(
            $basket,
            $user,
            'stripe_card',
            'pi_test_conditions_' . $this->testRunId
        );

        $this->assertTrue($result->isSuccess());
        $contractId = $result->getContractId();
        $this->assertNotNull($contractId);

        $contract = $this->contractRepository->findById($contractId);
        $this->assertNotNull($contract);

        $conditions = $contract->getConditions();
        $this->assertCount(2, $conditions, 'Should have 2 default conditions');

        $conditionTypes = array_map(fn($c) => $c->getType(), $conditions);
        $this->assertContains(ContractCondition::TYPE_PAYMENT_AUTHORIZED, $conditionTypes);
        $this->assertContains(ContractCondition::TYPE_FRAUD_CHECK, $conditionTypes);

        // Verify conditions are stored in database
        $dbRow = $this->connection->fetchAssociative(
            'SELECT OXCONDITIONS FROM osc_payment_contract WHERE OXID = :id',
            ['id' => $contractId]
        );
        $dbConditions = json_decode($dbRow['OXCONDITIONS'], true);
        $this->assertCount(2, $dbConditions);
    }

    // =========================================================================
    // PHASE 2: Contract State Machine Transitions (with DB persistence)
    // =========================================================================

    /**
     * @group integration
     * @group e2e
     * @group database
     */
    public function testContractStateMachine_PersistsStateTransitions(): void
    {
        $contract = $this->createContractDirectly($this->generateTestContractId('state_machine'));
        $orderId = $this->generateTestOrderId('sm');

        // Save DRAFT state
        $this->contractRepository->save($contract);
        $this->assertDatabaseState($contract->getId(), 'draft');

        // Transition to NOT_FINISHED then PENDING
        $contract->addCondition(ContractCondition::paymentAuthorized());
        $contract->transitionToNotFinished($orderId);
        $contract->transitionToPending();
        $this->contractRepository->save($contract);
        $this->assertDatabaseState($contract->getId(), 'pending');

        // Fulfill condition -> READY_TO_COMMIT
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED, [
            'authorizationId' => 'auth_' . $this->testRunId,
        ]);
        $this->contractRepository->save($contract);
        $this->assertDatabaseState($contract->getId(), 'ready_to_commit');

        // Commit to order -> COMMITTED
        $contract->commitToOrder($orderId);
        $this->contractRepository->save($contract);
        $this->assertDatabaseState($contract->getId(), 'committed');
        $this->assertDatabaseOrderId($contract->getId(), $orderId);

        // Fulfill -> FULFILLED
        $contract->fulfill();
        $this->contractRepository->save($contract);
        $this->assertDatabaseState($contract->getId(), 'fulfilled');
        $this->assertDatabaseFulfilledAt($contract->getId());
    }

    /**
     * @group integration
     * @group e2e
     * @group database
     */
    public function testContractStateMachine_TransitionsToReadyToCommitWhenAllConditionsFulfilled(): void
    {
        $contract = $this->createContractDirectly($this->generateTestContractId('all_conditions'));

        $contract->addCondition(ContractCondition::paymentAuthorized());
        $contract->addCondition(ContractCondition::fraudCheck());
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();
        $this->contractRepository->save($contract);

        $this->assertFalse($contract->areAllConditionsFulfilled());

        // Fulfill first condition
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED, [
            'authorizationId' => 'auth_test_123',
            'providerOrderId' => 'pi_test_456',
        ]);
        $this->contractRepository->save($contract);

        $this->assertFalse($contract->areAllConditionsFulfilled());
        $this->assertTrue($contract->getState()->isPending(), 'Should still be PENDING');

        // Fulfill second condition
        $contract->fulfillCondition(ContractCondition::TYPE_FRAUD_CHECK, [
            'score' => 95,
            'risk' => 'low',
        ]);
        $this->contractRepository->save($contract);

        $this->assertTrue($contract->areAllConditionsFulfilled());
        $this->assertTrue($contract->getState()->isReadyToCommit(), 'Should transition to READY_TO_COMMIT');

        // Verify in database
        $this->assertDatabaseState($contract->getId(), 'ready_to_commit');
    }

    // =========================================================================
    // PHASE 3: Condition Fulfillment Tracking (with DB persistence)
    // =========================================================================

    /**
     * @group integration
     * @group e2e
     * @group database
     */
    public function testConditionFulfillment_PersistsPaymentAuthorizationData(): void
    {
        $contract = $this->createContractDirectly($this->generateTestContractId('payment_auth'));
        $contract->addCondition(ContractCondition::paymentAuthorized());
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();

        $authData = [
            'authorizationId' => 'auth_stripe_' . $this->testRunId,
            'providerOrderId' => 'pi_live_' . $this->testRunId,
            'amount' => 15000,
            'currency' => 'eur',
        ];

        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED, $authData);
        $this->contractRepository->save($contract);

        // Verify in database
        $dbRow = $this->connection->fetchAssociative(
            'SELECT OXCONDITIONS FROM osc_payment_contract WHERE OXID = :id',
            ['id' => $contract->getId()]
        );

        $dbConditions = json_decode($dbRow['OXCONDITIONS'], true);
        $paymentCondition = $dbConditions[0];

        $this->assertEquals('fulfilled', $paymentCondition['status']);
        $this->assertEquals($authData['authorizationId'], $paymentCondition['data']['authorizationId']);
        $this->assertNotNull($paymentCondition['fulfilledAt']);
    }

    /**
     * @group integration
     * @group e2e
     * @group database
     */
    public function testConditionFulfillment_PersistsFailedCondition(): void
    {
        $contract = $this->createContractDirectly($this->generateTestContractId('failed_condition'));
        $contract->addCondition(ContractCondition::fraudCheck());
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();

        $contract->failCondition(ContractCondition::TYPE_FRAUD_CHECK, 'High risk score detected');
        $this->contractRepository->save($contract);

        // Verify in database
        $dbRow = $this->connection->fetchAssociative(
            'SELECT OXSTATE, OXCONDITIONS FROM osc_payment_contract WHERE OXID = :id',
            ['id' => $contract->getId()]
        );

        $this->assertEquals('failed', $dbRow['OXSTATE']);

        $dbConditions = json_decode($dbRow['OXCONDITIONS'], true);
        $fraudCondition = $dbConditions[0];

        $this->assertEquals('failed', $fraudCondition['status']);
        $this->assertEquals('High risk score detected', $fraudCondition['failureReason']);
    }

    // =========================================================================
    // PHASE 4: ThankyouController - Order Completion (with DB)
    // =========================================================================

    /**
     * @group integration
     * @group e2e
     * @group database
     */
    public function testOrderCompletion_ConfirmsOrderWithContractId(): void
    {
        // Simulate: OrderController created contract, conditions fulfilled
        $basket = $this->createValidBasket(250.00, 'EUR');
        $user = $this->createValidUser($this->generateTestUserId('user_complete'));

        $checkoutResult = $this->orchestrator->processCheckout(
            $basket,
            $user,
            'stripe_card',
            'pi_complete_' . $this->testRunId
        );

        $this->assertTrue($checkoutResult->isSuccess());
        $contractId = $checkoutResult->getContractId();
        $orderId = $this->generateTestOrderId('confirm');

        // Simulate: ThankyouController.render() calls confirmOrderCompletion
        $result = $this->orchestrator->confirmOrderCompletion($orderId, $contractId);

        $this->assertInstanceOf(OrderConfirmationResult::class, $result);
        $this->assertTrue($result->isSuccess());
    }

    /**
     * @group integration
     * @group e2e
     * @group database
     */
    public function testOrderCompletion_FailsWithoutContractId(): void
    {
        $result = $this->orchestrator->confirmOrderCompletion('order_no_contract', null);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('No contract ID', $result->getErrorMessage());
    }

    // =========================================================================
    // PHASE 5: Complete Flow Integration (with DB)
    // =========================================================================

    /**
     * @group integration
     * @group e2e
     * @group database
     */
    public function testCompleteFlow_FromOrderToThankyou_PersistedInDatabase(): void
    {
        $flowId = substr($this->testRunId, 0, 8);

        // STEP 1: OrderController.execute() - Customer clicks "Place Order"
        $basket = $this->createValidBasket(499.99, 'EUR');
        $user = $this->createValidUser($this->generateTestUserId('user_flow_' . $flowId));

        $checkoutResult = $this->orchestrator->processCheckout(
            $basket,
            $user,
            'stripe_card',
            'pi_flow_' . $flowId
        );

        $this->assertTrue($checkoutResult->isSuccess(), 'Step 1: Checkout should succeed');
        $contractId = $checkoutResult->getContractId();
        $this->assertNotNull($contractId);

        // Verify Step 1 in database
        $this->assertDatabaseState($contractId, 'draft');

        // STEP 2: Contract exists with conditions
        $contract = $this->contractRepository->findById($contractId);
        $this->assertNotNull($contract, 'Step 2: Contract should exist');
        $this->assertCount(2, $contract->getConditions(), 'Step 2: Should have 2 conditions');

        // STEP 3: Create order and simulate condition fulfillment
        $orderId = $this->generateTestOrderId('flow');
        $contract->transitionToNotFinished($orderId);
        $contract->transitionToPending();
        $this->contractRepository->save($contract);
        $this->assertDatabaseState($contractId, 'pending');

        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED, [
            'authorizationId' => 'auth_flow_' . $flowId,
        ]);
        $this->contractRepository->save($contract);

        $contract->fulfillCondition(ContractCondition::TYPE_FRAUD_CHECK, [
            'score' => 99,
            'risk' => 'low',
        ]);
        $this->contractRepository->save($contract);

        $this->assertTrue($contract->areAllConditionsFulfilled(), 'Step 3: All conditions fulfilled');
        $this->assertTrue($contract->getState()->isReadyToCommit(), 'Step 3: Ready to commit');
        $this->assertDatabaseState($contractId, 'ready_to_commit');

        // STEP 4: Commit to order
        $contract->commitToOrder($orderId);
        $this->contractRepository->save($contract);

        $this->assertTrue($contract->getState()->isCommitted(), 'Step 4: Contract committed');
        $this->assertEquals($orderId, $contract->getOrderId(), 'Step 4: Order ID linked');
        $this->assertDatabaseState($contractId, 'committed');
        $this->assertDatabaseOrderId($contractId, $orderId);

        // STEP 5: ThankyouController.render()
        $confirmResult = $this->orchestrator->confirmOrderCompletion($orderId, $contractId);
        $this->assertTrue($confirmResult->isSuccess(), 'Step 5: Order confirmation should succeed');

        // STEP 6: Simulate webhook payment capture
        $contract->fulfill();
        $this->contractRepository->save($contract);

        $this->assertTrue($contract->getState()->isFulfilled(), 'Step 6: Contract fulfilled');
        $this->assertNotNull($contract->getFulfilledAt(), 'Step 6: FulfilledAt set');
        $this->assertDatabaseState($contractId, 'fulfilled');
        $this->assertDatabaseFulfilledAt($contractId);
    }

    /**
     * @group integration
     * @group e2e
     * @group database
     */
    public function testCompleteFlow_ContractCancellationPersisted(): void
    {
        $basket = $this->createValidBasket(150.00, 'EUR');
        $user = $this->createValidUser($this->generateTestUserId('user_cancel'));

        $checkoutResult = $this->orchestrator->processCheckout(
            $basket,
            $user,
            'stripe_card',
            'pi_cancel_' . $this->testRunId
        );

        $this->assertTrue($checkoutResult->isSuccess());
        $contractId = $checkoutResult->getContractId();

        $contract = $this->contractRepository->findById($contractId);
        $this->assertNotNull($contract);

        $contract->addCondition(ContractCondition::paymentAuthorized());
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();
        $this->contractRepository->save($contract);

        // Simulate: Customer cancels payment
        $contract->cancel('Customer cancelled payment');
        $this->contractRepository->save($contract);

        $this->assertTrue($contract->getState()->isCancelled(), 'Contract should be cancelled');
        $this->assertDatabaseState($contractId, 'cancelled');
    }

    // =========================================================================
    // PHASE 6: Provider Information Tracking (with DB)
    // =========================================================================

    /**
     * @group integration
     * @group e2e
     * @group database
     */
    public function testProviderInfo_PersistsStripePaymentIntent(): void
    {
        $contract = $this->createContractDirectly($this->generateTestContractId('provider_info'));

        $providerOrderId = 'pi_3ABC_' . $this->testRunId;
        $redirectUrl = 'https://checkout.stripe.com/c/pay/cs_test_' . $this->testRunId;

        $contract->setProvider('stripe', $providerOrderId, $redirectUrl);
        $this->contractRepository->save($contract);

        // Verify in database
        $dbRow = $this->connection->fetchAssociative(
            'SELECT OXPROVIDER, OXPROVIDERORDERID FROM osc_payment_contract WHERE OXID = :id',
            ['id' => $contract->getId()]
        );

        $this->assertEquals('stripe', $dbRow['OXPROVIDER']);
        $this->assertEquals($providerOrderId, $dbRow['OXPROVIDERORDERID']);

        // Verify can find by provider order ID
        $found = $this->contractRepository->findByProviderOrderId($providerOrderId);
        $this->assertNotNull($found);
        $this->assertEquals($contract->getId(), $found->getId());
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Generate test contract ID (max 32 chars for OXID column).
     * Format: e2e_HHMMSS_XXXX_suffix = 4 + 10 + 1 + suffix (max 17 chars for suffix)
     */
    private function generateTestContractId(string $suffix): string
    {
        $id = self::TEST_PREFIX . $this->testRunId . '_' . $suffix;
        // Ensure max 32 chars
        return substr($id, 0, 32);
    }

    /**
     * Generate test user ID (max 32 chars for OXUSERID column).
     */
    private function generateTestUserId(string $suffix): string
    {
        $id = self::TEST_PREFIX . $suffix . '_' . $this->testRunId;
        // Ensure max 32 chars
        return substr($id, 0, 32);
    }

    /**
     * Generate test order ID (max 32 chars for OXORDERID column).
     */
    private function generateTestOrderId(string $suffix): string
    {
        $id = 'ord_' . $suffix . '_' . $this->testRunId;
        // Ensure max 32 chars
        return substr($id, 0, 32);
    }

    private function createValidBasket(float $total, string $currency): object
    {
        return new class ($total, $currency) {
            public float $totalGross;
            public float $totalNet;
            public float $totalVat;
            public string $currency;

            public function __construct(float $total, string $currency)
            {
                $this->totalGross = $total;
                $this->totalNet = $total / 1.19;
                $this->totalVat = $total - $this->totalNet;
                $this->currency = $currency;
            }

            public function getProductsCount(): int
            {
                return 2;
            }

            public function getPaymentId(): string
            {
                return 'stripe_card';
            }

            public function getBruttoSum(): float
            {
                return $this->totalGross;
            }

            public function getPrice(): object
            {
                $totalGross = $this->totalGross;
                $totalNet = $this->totalNet;
                $totalVat = $this->totalVat;

                return new class ($totalGross, $totalNet, $totalVat) {
                    public function __construct(
                        private float $gross,
                        private float $net,
                        private float $vat
                    ) {
                    }

                    public function getBruttoPrice(): float
                    {
                        return $this->gross;
                    }

                    public function getNettoPrice(): float
                    {
                        return $this->net;
                    }

                    public function getVatValue(): float
                    {
                        return $this->vat;
                    }
                };
            }

            public function getBasketCurrency(): object
            {
                return (object)['name' => $this->currency];
            }

            public function getContents(): array
            {
                return [];
            }

            public function getDiscounts(): array
            {
                return [];
            }
        };
    }

    private function createValidUser(string $userId): object
    {
        return new class ($userId) {
            private string $id;

            public function __construct(string $id)
            {
                $this->id = $id;
            }

            public function getId(): string
            {
                return $this->id;
            }
        };
    }

    private function createContractDirectly(string $contractId): PaymentContract
    {
        $basketSnapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
            'capturedAt' => date('Y-m-d H:i:s'),
        ]);

        return new PaymentContract(
            shopId: 1,
            userId: $this->generateTestUserId('direct'),
            basketSnapshot: $basketSnapshot,
            id: $contractId
        );
    }

    // =========================================================================
    // Database Assertion Helpers
    // =========================================================================

    private function assertDatabaseState(string $contractId, string $expectedState): void
    {
        $dbRow = $this->connection->fetchAssociative(
            'SELECT OXSTATE FROM osc_payment_contract WHERE OXID = :id',
            ['id' => $contractId]
        );

        $this->assertNotFalse($dbRow, "Contract {$contractId} should exist in database");
        $this->assertEquals(
            $expectedState,
            $dbRow['OXSTATE'],
            "Contract {$contractId} should have state '{$expectedState}' in database"
        );
    }

    private function assertDatabaseOrderId(string $contractId, string $expectedOrderId): void
    {
        $dbRow = $this->connection->fetchAssociative(
            'SELECT OXORDERID FROM osc_payment_contract WHERE OXID = :id',
            ['id' => $contractId]
        );

        $this->assertNotFalse($dbRow, "Contract {$contractId} should exist in database");
        $this->assertEquals(
            $expectedOrderId,
            $dbRow['OXORDERID'],
            "Contract {$contractId} should have order ID '{$expectedOrderId}' in database"
        );
    }

    private function assertDatabaseFulfilledAt(string $contractId): void
    {
        $dbRow = $this->connection->fetchAssociative(
            'SELECT OXFULFILLEDAT FROM osc_payment_contract WHERE OXID = :id',
            ['id' => $contractId]
        );

        $this->assertNotFalse($dbRow, "Contract {$contractId} should exist in database");
        $this->assertNotNull(
            $dbRow['OXFULFILLEDAT'],
            "Contract {$contractId} should have OXFULFILLEDAT set in database"
        );
    }
}
