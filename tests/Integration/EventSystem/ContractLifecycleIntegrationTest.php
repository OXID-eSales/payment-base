<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Integration\EventSystem;

use OxidEsales\PaymentComponent\EventSystem\EventDispatcher;
use OxidEsales\PaymentComponent\EventSystem\Handler\ContractCreationHandler;
use OxidEsales\PaymentComponent\EventSystem\Handler\ContractConditionResolverHandler;
use OxidEsales\PaymentComponent\EventSystem\Handler\EarlyOrderCreationHandler;
use OxidEsales\PaymentComponent\EventSystem\Handler\PaymentAuthorizationHandler;
use OxidEsales\PaymentComponent\EventSystem\Handler\OrderCreationHandler;
use OxidEsales\PaymentComponent\EventSystem\Handler\ContractFulfillmentHandler;
use OxidEsales\PaymentComponent\EventSystem\Handler\ContractCleanupHandler;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\PaymentInitiatedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\WebhookReceivedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractCreatedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractDraftCompletedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractTransitionedToPendingEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractReadyToCommitEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractCommittedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractFulfilledEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractCancelledEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractExpiredEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\Repository\ContractRepository;
use OxidEsales\PaymentComponent\Service\ContractService;
use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\Tests\Unit\EventSystem\Handler\Support\InMemoryOrderRepository;
use OxidEsales\PaymentComponent\Tests\Unit\EventSystem\Handler\Support\InMemoryShopOrderService;
use PHPUnit\Framework\TestCase;

class ContractLifecycleIntegrationTest extends TestCase
{
    private EventDispatcher $dispatcher;
    private ContractRepository $contractRepository;
    private InMemoryOrderRepository $orderRepository;
    private InMemoryShopOrderService $shopOrderService;
    private ContractService $contractService;

    protected function setUp(): void
    {
        $this->dispatcher = new EventDispatcher();
        $this->contractRepository = new ContractRepository();
        $this->orderRepository = new InMemoryOrderRepository();
        $this->shopOrderService = new InMemoryShopOrderService();
        $this->contractService = new ContractService($this->contractRepository);

        $this->registerHandlers();
    }

    private function registerHandlers(): void
    {
        $contractCreationHandler = new ContractCreationHandler(
            $this->contractService,
            $this->dispatcher
        );

        $contractConditionResolverHandler = new ContractConditionResolverHandler(
            $this->contractRepository,
            $this->dispatcher
        );

        // STRP-74: EarlyOrderCreationHandler for new flow DRAFT → NOT_FINISHED → PENDING
        $earlyOrderCreationHandler = new EarlyOrderCreationHandler(
            $this->contractRepository,
            $this->shopOrderService,
            $this->dispatcher
        );

        $paymentAuthorizationHandler = new PaymentAuthorizationHandler(
            $this->contractRepository,
            $this->dispatcher
        );

        $orderCreationHandler = new OrderCreationHandler(
            $this->contractRepository,
            $this->orderRepository,
            $this->dispatcher
        );

        $contractFulfillmentHandler = new ContractFulfillmentHandler(
            $this->contractRepository,
            $this->orderRepository,
            $this->dispatcher
        );

        $contractCleanupHandler = new ContractCleanupHandler(
            $this->contractRepository
        );

        $this->dispatcher->addListener(
            PaymentInitiatedEvent::class,
            [$contractCreationHandler, 'handle']
        );

        $this->dispatcher->addListener(
            ContractCreatedEvent::class,
            [$contractConditionResolverHandler, 'handle']
        );

        // STRP-74: Register EarlyOrderCreationHandler for ContractDraftCompletedEvent
        $this->dispatcher->addListener(
            ContractDraftCompletedEvent::class,
            [$earlyOrderCreationHandler, 'handle']
        );

        $this->dispatcher->addListener(
            ContractTransitionedToPendingEvent::class,
            [$paymentAuthorizationHandler, 'handle']
        );

        $this->dispatcher->addListener(
            ContractReadyToCommitEvent::class,
            [$orderCreationHandler, 'handle']
        );

        $this->dispatcher->addListener(
            WebhookReceivedEvent::class,
            [$contractFulfillmentHandler, 'handle']
        );

        $this->dispatcher->addListener(
            ContractCancelledEvent::class,
            [$contractCleanupHandler, 'handle']
        );

        $this->dispatcher->addListener(
            ContractExpiredEvent::class,
            [$contractCleanupHandler, 'handle']
        );
    }

    private function createBasket(): object
    {
        return new class {
            public function getPrice(): object
            {
                return new class {
                    public function getBruttoPrice(): float
                    {
                        return 130.0;
                    }

                    public function getNettoPrice(): float
                    {
                        return 109.24;
                    }

                    public function getVatValue(): float
                    {
                        return 20.76;
                    }
                };
            }

            public function getBasketCurrency(): object
            {
                return (object)['name' => 'EUR'];
            }

            public function getContents(): array
            {
                return [];
            }

            public function getDiscounts(): array
            {
                return [];
            }

            public function getProductsCount(): int
            {
                return 3;
            }
        };
    }

    public function testCompleteContractLifecycleHappyPath(): void
    {
        $basket = $this->createBasket();

        $context = new EventContext([
            'userId' => 'user123',
            'basket' => $basket,
            'conditionTypes' => ['payment_authorized'],
            'authorizationId' => 'auth_123',
            'providerOrderId' => 'pi_456',
        ]);

        $paymentInitiatedEvent = new PaymentInitiatedEvent(
            $context,
            'stripe',
            130.0,
            'EUR',
            'http://example.com/return',
            'http://example.com/cancel'
        );

        $this->dispatcher->dispatch($paymentInitiatedEvent);

        $contract = $context->getContract();
        $this->assertNotNull($contract, 'Contract should be created');

        // Reload contract from repository to get latest state after all handlers
        $contract = $this->contractRepository->findById($contract->getId());

        $this->assertTrue($contract->areAllConditionsFulfilled(), 'All conditions should be fulfilled');
        $this->assertTrue($contract->getState()->isCommitted(), 'Contract should be COMMITTED after full lifecycle');
        $this->assertNotNull($contract->getOrderId(), 'Order should be created');

        $orders = $this->orderRepository->findAll();
        $this->assertCount(1, $orders, 'Order should be saved');
        $this->assertEquals('user123', $orders[0]->getUserId());
        $this->assertEquals(130.0, $orders[0]->getTotalGross());

        $webhookContext = new EventContext([
            'contractId' => $contract->getId(),
        ]);

        $webhookEvent = new WebhookReceivedEvent(
            $webhookContext,
            'stripe',
            'payment_intent.succeeded',
            ['id' => 'pi_123', 'status' => 'succeeded'],
            'sig_123'
        );

        $this->dispatcher->dispatch($webhookEvent);

        $finalContract = $this->contractRepository->findById($contract->getId());
        $this->assertTrue($finalContract->getState()->isFulfilled(), 'Contract should be FULFILLED');
        $this->assertNotNull($finalContract->getFulfilledAt(), 'Contract should have fulfillment timestamp');

        $order = $this->orderRepository->findById(1);
        $this->assertEquals('completed', $order->getStatus(), 'Order should be completed');
    }

    public function testContractFailureWhenPaymentDeclined(): void
    {
        $basket = $this->createBasket();

        // Don't provide authorizationId - payment won't be auto-fulfilled
        $context = new EventContext([
            'userId' => 'user456',
            'basket' => $basket,
            'conditionTypes' => ['payment_authorized'],
        ]);

        $paymentInitiatedEvent = new PaymentInitiatedEvent(
            $context,
            'stripe',
            130.0,
            'EUR',
            'http://example.com/return',
            'http://example.com/cancel'
        );

        $this->dispatcher->dispatch($paymentInitiatedEvent);

        $contract = $context->getContract();
        $this->assertNotNull($contract, 'Contract should be created');

        // Reload to get latest state - contract auto-commits even without explicit auth
        $contract = $this->contractRepository->findById($contract->getId());
        $this->assertTrue($contract->getState()->isCommitted(), 'Contract should be in COMMITTED state');

        // Simulate payment declined after commitment
        $contract->fail('Payment declined by issuer');
        $this->contractRepository->save($contract);

        $finalContract = $this->contractRepository->findById($contract->getId());
        $this->assertTrue($finalContract->getState()->isFailed(), 'Contract should be FAILED');
        $this->assertTrue($finalContract->getState()->isTerminal(), 'Failed state should be terminal');

        $orders = $this->orderRepository->findAll();
        $this->assertCount(1, $orders, 'Order created before failure');
    }

    public function testContractCancellationFlow(): void
    {
        $basket = $this->createBasket();

        // Don't provide authorizationId - payment won't be auto-fulfilled
        $context = new EventContext([
            'userId' => 'user789',
            'basket' => $basket,
            'conditionTypes' => ['payment_authorized'],
        ]);

        $paymentInitiatedEvent = new PaymentInitiatedEvent(
            $context,
            'stripe',
            130.0,
            'EUR',
            'http://example.com/return',
            'http://example.com/cancel'
        );

        $this->dispatcher->dispatch($paymentInitiatedEvent);

        $contract = $context->getContract();
        $this->assertNotNull($contract, 'Contract should be created');

        // Reload to get latest state - contract auto-commits
        $contract = $this->contractRepository->findById($contract->getId());
        $this->assertTrue($contract->getState()->isCommitted(), 'Contract should be in COMMITTED state');

        $contract->cancel('User cancelled payment');
        $this->contractRepository->save($contract);

        $cancelContext = new EventContext(['userId' => 'user789']);
        $cancelEvent = new ContractCancelledEvent(
            $contract,
            $cancelContext,
            'User cancelled payment'
        );

        $this->dispatcher->dispatch($cancelEvent);

        $finalContract = $this->contractRepository->findById($contract->getId());
        $this->assertTrue($finalContract->getState()->isCancelled(), 'Contract should be CANCELLED');
        $this->assertTrue($finalContract->getState()->isTerminal(), 'Cancelled state should be terminal');

        $orders = $this->orderRepository->findAll();
        $this->assertCount(1, $orders, 'Order created before cancellation');
    }

    public function testContractExpirationFlow(): void
    {
        $basket = $this->createBasket();

        // Don't provide authorizationId - payment won't be auto-fulfilled
        $context = new EventContext([
            'userId' => 'user999',
            'basket' => $basket,
            'conditionTypes' => ['payment_authorized'],
        ]);

        $paymentInitiatedEvent = new PaymentInitiatedEvent(
            $context,
            'stripe',
            130.0,
            'EUR',
            'http://example.com/return',
            'http://example.com/cancel'
        );

        $this->dispatcher->dispatch($paymentInitiatedEvent);

        $contract = $context->getContract();
        $this->assertNotNull($contract, 'Contract should be created');

        // Reload to get latest state - contract auto-commits
        $contract = $this->contractRepository->findById($contract->getId());
        $this->assertTrue($contract->getState()->isCommitted(), 'Contract should be in COMMITTED state');

        $contract->expire();
        $this->contractRepository->save($contract);

        $expireContext = new EventContext(['system' => 'cron']);
        $expireEvent = new ContractExpiredEvent(
            $contract,
            $expireContext,
            time()
        );

        $this->dispatcher->dispatch($expireEvent);

        $finalContract = $this->contractRepository->findById($contract->getId());
        $this->assertTrue($finalContract->getState()->isExpired(), 'Contract should be EXPIRED');
        $this->assertTrue($finalContract->getState()->isTerminal(), 'Expired state should be terminal');

        $orders = $this->orderRepository->findAll();
        $this->assertCount(1, $orders, 'Order created before expiration');
    }
}
