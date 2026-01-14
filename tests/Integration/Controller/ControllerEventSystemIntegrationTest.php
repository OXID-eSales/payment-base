<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Integration\Controller;

use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\Contract\ContractCondition;
use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcher;
use OxidEsales\PaymentComponent\EventSystem\EventListenerProvider;
use OxidEsales\PaymentComponent\EventSystem\Handler\ContractCreationHandler;
use OxidEsales\PaymentComponent\EventSystem\Handler\ContractConditionResolverHandler;
use OxidEsales\PaymentComponent\EventSystem\Handler\EarlyOrderCreationHandler;
use OxidEsales\PaymentComponent\EventSystem\Handler\PaymentAuthorizationHandler;
use OxidEsales\PaymentComponent\EventSystem\Handler\OrderCreationHandler;
use OxidEsales\PaymentComponent\EventSystem\Handler\ContractFulfillmentHandler;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\PaymentInitiatedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\OrderCompletedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractCreatedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractDraftCompletedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractTransitionedToPendingEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractReadyToCommitEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractCommittedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\Event\EventInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepository;
use OxidEsales\PaymentComponent\Service\CheckoutOrchestrator;
use OxidEsales\PaymentComponent\Service\ContractService;
use OxidEsales\PaymentComponent\Tests\Unit\EventSystem\Handler\Support\InMemoryOrderRepository;
use OxidEsales\PaymentComponent\Tests\Unit\EventSystem\Handler\Support\InMemoryShopOrderService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Integration tests verifying the complete flow from Controllers through EventSystem to Handlers.
 *
 * These tests verify:
 * 1. OrderController.execute() → CheckoutOrchestrator → PaymentInitiatedEvent → Handlers
 * 2. ThankyouController.render() → CheckoutOrchestrator → OrderCompletedEvent → Handlers
 * 3. All handlers are executed in the correct order
 * 4. Events propagate correctly through the system
 * 5. Contract state machine transitions as expected
 *
 * @group integration
 * @group e2e
 * @group event-system
 * @group controller-integration
 */
final class ControllerEventSystemIntegrationTest extends TestCase
{
    private const TEST_PREFIX = 'e2e_ctrl_';
    private const SHOP_ID = 1;

    private ContractRepository $contractRepository;
    private EventDispatcher $eventDispatcher;
    private EventListenerProvider $listenerProvider;
    private CheckoutOrchestrator $orchestrator;
    private InMemoryOrderRepository $orderRepository;
    private InMemoryShopOrderService $shopOrderService;
    private ContractService $contractService;

    /** @var array<string, bool> Track which handlers were executed */
    private array $handlerExecutionLog = [];

    /** @var array<string, object> Track which events were dispatched */
    private array $eventDispatchLog = [];

    private string $testRunId;

    public function setUp(): void
    {
        parent::setUp();

        $this->testRunId = date('His') . '_' . substr(uniqid(), -4);

        // Reset execution logs
        $this->handlerExecutionLog = [];
        $this->eventDispatchLog = [];

        // Set up components with tracking (using in-memory repository)
        $this->setupEventSystem();
    }

    /**
     * Sets up the event system with real handlers that track execution.
     */
    private function setupEventSystem(): void
    {
        $this->contractRepository = new ContractRepository();
        $this->orderRepository = new InMemoryOrderRepository();
        $this->shopOrderService = new InMemoryShopOrderService();
        $this->contractService = new ContractService($this->contractRepository);

        // Create listener provider
        $this->listenerProvider = new EventListenerProvider();

        // Create event dispatcher with tracking
        $testCase = $this;
        $this->eventDispatcher = new class($this->listenerProvider, $testCase) extends EventDispatcher {
            private ControllerEventSystemIntegrationTest $testCase;

            public function __construct(EventListenerProvider $provider, ControllerEventSystemIntegrationTest $testCase)
            {
                parent::__construct($provider);
                $this->testCase = $testCase;
            }

            public function dispatch(EventInterface $event): EventInterface
            {
                $this->testCase->logEventDispatched($event);
                return parent::dispatch($event);
            }
        };

        // Register handlers with execution tracking
        $this->registerHandlersWithTracking();

        // Create orchestrator
        $this->orchestrator = new CheckoutOrchestrator(
            $this->eventDispatcher,
            new NullLogger()
        );
    }

    /**
     * Registers all handlers with execution tracking.
     */
    private function registerHandlersWithTracking(): void
    {
        // ContractCreationHandler
        $contractCreationHandler = new ContractCreationHandler(
            $this->contractService,
            $this->eventDispatcher
        );
        $this->listenerProvider->addListener(
            PaymentInitiatedEvent::class,
            function ($event) use ($contractCreationHandler) {
                $this->logHandlerExecuted('ContractCreationHandler');
                $contractCreationHandler->handle($event);
            }
        );

        // ContractConditionResolverHandler
        $conditionResolverHandler = new ContractConditionResolverHandler(
            $this->contractRepository,
            $this->eventDispatcher
        );
        $this->listenerProvider->addListener(
            ContractCreatedEvent::class,
            function ($event) use ($conditionResolverHandler) {
                $this->logHandlerExecuted('ContractConditionResolverHandler');
                $conditionResolverHandler->handle($event);
            }
        );

        // STRP-74: EarlyOrderCreationHandler for new flow DRAFT → NOT_FINISHED → PENDING
        $earlyOrderCreationHandler = new EarlyOrderCreationHandler(
            $this->contractRepository,
            $this->shopOrderService,
            $this->eventDispatcher
        );
        $this->listenerProvider->addListener(
            ContractDraftCompletedEvent::class,
            function ($event) use ($earlyOrderCreationHandler) {
                $this->logHandlerExecuted('EarlyOrderCreationHandler');
                $earlyOrderCreationHandler->handle($event);
            }
        );

        // PaymentAuthorizationHandler
        $paymentAuthHandler = new PaymentAuthorizationHandler(
            $this->contractRepository,
            $this->eventDispatcher
        );
        $this->listenerProvider->addListener(
            ContractTransitionedToPendingEvent::class,
            function ($event) use ($paymentAuthHandler) {
                $this->logHandlerExecuted('PaymentAuthorizationHandler');
                $paymentAuthHandler->handle($event);
            }
        );

        // OrderCreationHandler
        $orderCreationHandler = new OrderCreationHandler(
            $this->contractRepository,
            $this->orderRepository,
            $this->eventDispatcher
        );
        $this->listenerProvider->addListener(
            ContractReadyToCommitEvent::class,
            function ($event) use ($orderCreationHandler) {
                $this->logHandlerExecuted('OrderCreationHandler');
                $orderCreationHandler->handle($event);
            }
        );

        // ContractFulfillmentHandler (for webhook simulation)
        $fulfillmentHandler = new ContractFulfillmentHandler(
            $this->contractRepository,
            $this->orderRepository,
            $this->eventDispatcher
        );
        $this->listenerProvider->addListener(
            ContractCommittedEvent::class,
            function ($event) use ($fulfillmentHandler) {
                $this->logHandlerExecuted('ContractFulfillmentHandler');
                // Don't auto-fulfill on commit, this is for webhooks
            }
        );
    }

    public function logHandlerExecuted(string $handlerName): void
    {
        $this->handlerExecutionLog[$handlerName] = true;
    }

    public function logEventDispatched(object $event): void
    {
        $className = get_class($event);
        $shortName = substr($className, strrpos($className, '\\') + 1);
        $this->eventDispatchLog[$shortName] = $event;
    }

    // =========================================================================
    // TEST: OrderController.execute() → Event Chain
    // =========================================================================

    /**
     * Tests that OrderController flow triggers correct events and handlers.
     *
     * Simulates: OrderController.execute()
     *   → CheckoutOrchestrator.processCheckout()
     *     → dispatch(PaymentInitiatedEvent)
     *       → ContractCreationHandler
     *         → dispatch(ContractCreatedEvent)
     *           → ContractConditionResolverHandler
     *             → dispatch(ContractTransitionedToPendingEvent)
     *               → PaymentAuthorizationHandler
     *
     * @group controller-integration
     */
    public function testOrderControllerFlow_DispatchesPaymentInitiatedEvent(): void
    {
        // Simulate OrderController calling orchestrator
        $basket = $this->createBasketMock();
        $user = $this->createUserMock();

        $result = $this->orchestrator->processCheckout(
            $basket,
            $user,
            'stripe_card',
            'pi_test_' . $this->testRunId
        );

        // Assert orchestrator succeeded
        $this->assertTrue($result->isSuccess(), 'Checkout should succeed');
        $this->assertNotNull($result->getContractId(), 'Contract ID should be returned');

        // Assert PaymentInitiatedEvent was dispatched
        $this->assertArrayHasKey('PaymentInitiatedEvent', $this->eventDispatchLog);
    }

    /**
     * @group controller-integration
     */
    public function testOrderControllerFlow_TriggersContractCreationHandler(): void
    {
        $basket = $this->createBasketMock();
        $user = $this->createUserMock();

        $this->orchestrator->processCheckout($basket, $user, 'stripe_card');

        // Assert ContractCreationHandler was executed
        $this->assertArrayHasKey(
            'ContractCreationHandler',
            $this->handlerExecutionLog,
            'ContractCreationHandler should be executed'
        );
    }

    /**
     * @group controller-integration
     */
    public function testOrderControllerFlow_CreatesContractInRepository(): void
    {
        $basket = $this->createBasketMock();
        $user = $this->createUserMock();

        $result = $this->orchestrator->processCheckout($basket, $user, 'stripe_card');

        // Assert contract exists in repository
        $contractId = $result->getContractId();
        $this->assertNotNull($contractId);

        $contract = $this->contractRepository->findById($contractId);

        $this->assertNotNull($contract, 'Contract should exist in repository');
        $this->assertEquals('user_' . $this->testRunId, $contract->getUserId());
    }

    /**
     * @group controller-integration
     */
    public function testOrderControllerFlow_DispatchesContractCreatedEvent(): void
    {
        $basket = $this->createBasketMock();
        $user = $this->createUserMock();

        $this->orchestrator->processCheckout($basket, $user, 'stripe_card');

        // Assert ContractCreatedEvent was dispatched (by ContractCreationHandler)
        $this->assertArrayHasKey(
            'ContractCreatedEvent',
            $this->eventDispatchLog,
            'ContractCreatedEvent should be dispatched after contract creation'
        );
    }

    /**
     * @group controller-integration
     */
    public function testOrderControllerFlow_TriggersConditionResolverHandler(): void
    {
        $basket = $this->createBasketMock();
        $user = $this->createUserMock();

        $this->orchestrator->processCheckout($basket, $user, 'stripe_card');

        // Assert ContractConditionResolverHandler was executed
        $this->assertArrayHasKey(
            'ContractConditionResolverHandler',
            $this->handlerExecutionLog,
            'ContractConditionResolverHandler should be executed after ContractCreatedEvent'
        );
    }

    /**
     * @group controller-integration
     */
    public function testOrderControllerFlow_TransitionsContractToPending(): void
    {
        $basket = $this->createBasketMock();
        $user = $this->createUserMock();

        $result = $this->orchestrator->processCheckout($basket, $user, 'stripe_card');

        // Assert ContractTransitionedToPendingEvent was dispatched
        $this->assertArrayHasKey(
            'ContractTransitionedToPendingEvent',
            $this->eventDispatchLog,
            'Contract should transition to PENDING state'
        );
    }

    /**
     * @group controller-integration
     */
    public function testOrderControllerFlow_TriggersPaymentAuthorizationHandler(): void
    {
        $basket = $this->createBasketMock();
        $user = $this->createUserMock();

        $this->orchestrator->processCheckout($basket, $user, 'stripe_card');

        // Assert PaymentAuthorizationHandler was executed
        $this->assertArrayHasKey(
            'PaymentAuthorizationHandler',
            $this->handlerExecutionLog,
            'PaymentAuthorizationHandler should be executed after contract transitions to PENDING'
        );
    }

    /**
     * Tests the complete OrderController event chain in correct order.
     *
     * @group controller-integration
     */
    public function testOrderControllerFlow_ExecutesHandlersInCorrectOrder(): void
    {
        $basket = $this->createBasketMock();
        $user = $this->createUserMock();

        $this->orchestrator->processCheckout($basket, $user, 'stripe_card');

        // Get the order handlers were executed (based on array key order)
        $executedHandlers = array_keys($this->handlerExecutionLog);

        // Assert minimum required handlers were executed
        $this->assertContains('ContractCreationHandler', $executedHandlers);
        $this->assertContains('ContractConditionResolverHandler', $executedHandlers);
        $this->assertContains('PaymentAuthorizationHandler', $executedHandlers);

        // Assert correct order
        $creationIndex = array_search('ContractCreationHandler', $executedHandlers);
        $resolverIndex = array_search('ContractConditionResolverHandler', $executedHandlers);
        $authIndex = array_search('PaymentAuthorizationHandler', $executedHandlers);

        $this->assertLessThan(
            $resolverIndex,
            $creationIndex,
            'ContractCreationHandler should run before ContractConditionResolverHandler'
        );
        $this->assertLessThan(
            $authIndex,
            $resolverIndex,
            'ContractConditionResolverHandler should run before PaymentAuthorizationHandler'
        );
    }

    /**
     * Tests the complete event dispatch chain.
     *
     * @group controller-integration
     */
    public function testOrderControllerFlow_DispatchesEventsInCorrectOrder(): void
    {
        $basket = $this->createBasketMock();
        $user = $this->createUserMock();

        $this->orchestrator->processCheckout($basket, $user, 'stripe_card');

        // Assert all expected events were dispatched
        $dispatchedEvents = array_keys($this->eventDispatchLog);

        $this->assertContains('PaymentInitiatedEvent', $dispatchedEvents);
        $this->assertContains('ContractCreatedEvent', $dispatchedEvents);
        $this->assertContains('ContractTransitionedToPendingEvent', $dispatchedEvents);
    }

    // =========================================================================
    // TEST: ThankyouController.render() → Event Chain
    // =========================================================================

    /**
     * Tests that ThankyouController flow triggers correct events.
     *
     * Simulates: ThankyouController.render()
     *   → CheckoutOrchestrator.confirmOrderCompletion()
     *     → dispatch(OrderCompletedEvent)
     *
     * @group controller-integration
     */
    public function testThankyouControllerFlow_DispatchesOrderCompletedEvent(): void
    {
        // First create a contract via OrderController flow
        $basket = $this->createBasketMock();
        $user = $this->createUserMock();

        $result = $this->orchestrator->processCheckout($basket, $user, 'stripe_card');
        $contractId = $result->getContractId();

        // Reset logs
        $this->eventDispatchLog = [];
        $this->handlerExecutionLog = [];

        // Simulate ThankyouController calling orchestrator
        $orderId = 'ord_' . $this->testRunId;
        $confirmResult = $this->orchestrator->confirmOrderCompletion($orderId, $contractId);

        // Assert success
        $this->assertTrue($confirmResult->isSuccess(), 'Order confirmation should succeed');

        // Assert OrderCompletedEvent was dispatched
        $this->assertArrayHasKey(
            'OrderCompletedEvent',
            $this->eventDispatchLog,
            'OrderCompletedEvent should be dispatched'
        );
    }

    /**
     * @group controller-integration
     */
    public function testThankyouControllerFlow_WithoutContractId_DoesNotDispatchEvent(): void
    {
        $orderId = 'ord_no_contract_' . $this->testRunId;

        // Call without contract ID
        $result = $this->orchestrator->confirmOrderCompletion($orderId, null);

        // Assert failure
        $this->assertFalse($result->isSuccess());

        // Assert no events dispatched
        $this->assertEmpty($this->eventDispatchLog, 'No events should be dispatched without contract ID');
    }

    // =========================================================================
    // TEST: Full Flow - OrderController → ThankyouController
    // =========================================================================

    /**
     * Tests the complete checkout flow from OrderController to ThankyouController.
     *
     * @group controller-integration
     * @group complete-flow
     */
    public function testCompleteFlow_OrderToThankyou_ExecutesAllHandlers(): void
    {
        // PHASE 1: OrderController.execute()
        $basket = $this->createBasketMock();
        $user = $this->createUserMock();

        $checkoutResult = $this->orchestrator->processCheckout(
            $basket,
            $user,
            'stripe_card',
            'pi_complete_' . $this->testRunId
        );

        $this->assertTrue($checkoutResult->isSuccess());
        $contractId = $checkoutResult->getContractId();

        // Capture Phase 1 handlers
        $phase1Handlers = array_keys($this->handlerExecutionLog);
        $phase1Events = array_keys($this->eventDispatchLog);

        // Reset for Phase 2
        $this->handlerExecutionLog = [];
        $this->eventDispatchLog = [];

        // PHASE 2: ThankyouController.render()
        $orderId = 'ord_complete_' . $this->testRunId;
        $confirmResult = $this->orchestrator->confirmOrderCompletion($orderId, $contractId);

        $this->assertTrue($confirmResult->isSuccess());

        // Capture Phase 2 handlers
        $phase2Events = array_keys($this->eventDispatchLog);

        // Assert Phase 1 events
        $this->assertContains('PaymentInitiatedEvent', $phase1Events, 'Phase 1 should dispatch PaymentInitiatedEvent');
        $this->assertContains('ContractCreatedEvent', $phase1Events, 'Phase 1 should dispatch ContractCreatedEvent');

        // Assert Phase 1 handlers
        $this->assertContains('ContractCreationHandler', $phase1Handlers, 'Phase 1 should execute ContractCreationHandler');

        // Assert Phase 2 events
        $this->assertContains('OrderCompletedEvent', $phase2Events, 'Phase 2 should dispatch OrderCompletedEvent');
    }

    /**
     * Tests that contract state persists correctly through the flow.
     *
     * @group controller-integration
     * @group complete-flow
     */
    public function testCompleteFlow_ContractStatePersistsInRepository(): void
    {
        // Phase 1: Create contract
        $basket = $this->createBasketMock();
        $user = $this->createUserMock();

        $result = $this->orchestrator->processCheckout($basket, $user, 'stripe_card');
        $contractId = $result->getContractId();

        // Verify initial state in repository
        $contract = $this->contractRepository->findById($contractId);

        $this->assertNotNull($contract, 'Contract should exist');
        // STRP-74: State depends on handler execution (could be draft, not_finished, pending, or ready_to_commit)
        $this->assertContains($contract->getStateValue(), ['draft', 'not_finished', 'pending', 'ready_to_commit']);

        // Phase 2: Confirm order
        $orderId = 'ord_state_' . $this->testRunId;
        $this->orchestrator->confirmOrderCompletion($orderId, $contractId);

        // Contract state should still exist in repository (not deleted)
        $finalContract = $this->contractRepository->findById($contractId);

        $this->assertNotNull($finalContract, 'Contract should still exist after confirmation');
    }

    // =========================================================================
    // TEST: Error Scenarios
    // =========================================================================

    /**
     * @group controller-integration
     */
    public function testOrderControllerFlow_WithEmptyBasket_DoesNotDispatchEvents(): void
    {
        $emptyBasket = $this->createEmptyBasketMock();
        $user = $this->createUserMock();

        $result = $this->orchestrator->processCheckout($emptyBasket, $user, 'stripe_card');

        $this->assertFalse($result->isSuccess());
        $this->assertEquals('EMPTY_BASKET', $result->getErrorCode());
        $this->assertEmpty($this->eventDispatchLog, 'No events should be dispatched for empty basket');
    }

    /**
     * @group controller-integration
     */
    public function testOrderControllerFlow_WithInvalidUser_DoesNotDispatchEvents(): void
    {
        $basket = $this->createBasketMock();
        $invalidUser = $this->createInvalidUserMock();

        $result = $this->orchestrator->processCheckout($basket, $invalidUser, 'stripe_card');

        $this->assertFalse($result->isSuccess());
        $this->assertEquals('INVALID_USER', $result->getErrorCode());
        $this->assertEmpty($this->eventDispatchLog, 'No events should be dispatched for invalid user');
    }

    // =========================================================================
    // TEST: Event Context Propagation
    // =========================================================================

    /**
     * Tests that event context carries data through the handler chain.
     *
     * @group controller-integration
     */
    public function testEventContext_CarriesDataThroughHandlerChain(): void
    {
        $basket = $this->createBasketMock();
        $user = $this->createUserMock();

        $this->orchestrator->processCheckout(
            $basket,
            $user,
            'stripe_card',
            'pi_context_test_' . $this->testRunId
        );

        // Get the PaymentInitiatedEvent that was dispatched
        /** @var PaymentInitiatedEvent $event */
        $event = $this->eventDispatchLog['PaymentInitiatedEvent'] ?? null;
        $this->assertNotNull($event);

        $context = $event->getContext();

        // Assert context has expected data
        $this->assertEquals('user_' . $this->testRunId, $context->get('userId'));
        $this->assertEquals('stripe_card', $context->get('paymentMethodId'));
        $this->assertEquals('pi_context_test_' . $this->testRunId, $context->get('providerTransactionId'));

        // Assert contract was set in context by handler
        $contract = $context->getContract();
        $this->assertNotNull($contract, 'Contract should be set in context by ContractCreationHandler');
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function createBasketMock(): object
    {
        return new class($this->testRunId) {
            private string $testRunId;

            public function __construct(string $testRunId)
            {
                $this->testRunId = $testRunId;
            }

            public function getPaymentId(): string
            {
                return 'stripe_card';
            }

            public function getProductsCount(): int
            {
                return 2;
            }

            public function getBruttoSum(): float
            {
                return 150.0;
            }

            public function getBasketCurrency(): object
            {
                return (object)['name' => 'EUR'];
            }

            // Additional methods for basket snapshot
            public function getNettoSum(): float
            {
                return 126.05;
            }

            public function getVatSum(): float
            {
                return 23.95;
            }
        };
    }

    private function createEmptyBasketMock(): object
    {
        return new class {
            public function getPaymentId(): string
            {
                return 'stripe_card';
            }

            public function getProductsCount(): int
            {
                return 0;
            }

            public function getBruttoSum(): float
            {
                return 0.0;
            }

            public function getBasketCurrency(): object
            {
                return (object)['name' => 'EUR'];
            }
        };
    }

    private function createUserMock(): object
    {
        $testRunId = $this->testRunId;
        return new class($testRunId) {
            private string $id;

            public function __construct(string $testRunId)
            {
                $this->id = 'user_' . $testRunId;
            }

            public function getId(): string
            {
                return $this->id;
            }
        };
    }

    private function createInvalidUserMock(): object
    {
        return new class {
            public function getId(): string
            {
                return ''; // Empty ID = invalid
            }
        };
    }
}
