<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Integration\Controller;

use OxidEsales\PaymentComponent\Service\CheckoutOrchestratorInterface;
use OxidEsales\PaymentComponent\Service\CheckoutOrchestrator;
use OxidEsales\PaymentComponent\Service\Result\CheckoutResult;
use OxidEsales\PaymentComponent\Service\Result\OrderConfirmationResult;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcher;
use OxidEsales\PaymentComponent\EventSystem\EventListenerProvider;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\PaymentInitiatedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Integration tests for the checkout flow.
 *
 * Tests verify that:
 * - Components can be instantiated and wired together
 * - Event dispatch works through the components
 * - Orchestrator processes checkout requests correctly
 *
 * Note: These tests instantiate components directly rather than using the DI container
 * to ensure they work in CI environments where the module may not be fully activated.
 *
 * @group integration
 * @group checkout
 */
class CheckoutFlowIntegrationTest extends TestCase
{
    private EventDispatcher $eventDispatcher;
    private CheckoutOrchestrator $orchestrator;

    protected function setUp(): void
    {
        parent::setUp();

        // Create EventListenerProvider with empty handlers (no tagged iterator in tests)
        $listenerProvider = new EventListenerProvider([]);

        // Create EventDispatcher with the provider
        $this->eventDispatcher = new EventDispatcher($listenerProvider);

        // Create CheckoutOrchestrator with the dispatcher
        $this->orchestrator = new CheckoutOrchestrator(
            $this->eventDispatcher,
            new NullLogger()
        );
    }

    /**
     * @group integration
     */
    public function testCheckoutOrchestrator_ImplementsInterface(): void
    {
        $this->assertInstanceOf(CheckoutOrchestratorInterface::class, $this->orchestrator);
        $this->assertInstanceOf(CheckoutOrchestrator::class, $this->orchestrator);
    }

    /**
     * @group integration
     */
    public function testEventDispatcher_ImplementsInterface(): void
    {
        $this->assertInstanceOf(EventDispatcherInterface::class, $this->eventDispatcher);
        $this->assertInstanceOf(EventDispatcher::class, $this->eventDispatcher);
    }

    /**
     * @group integration
     */
    public function testCheckoutOrchestrator_HasEventDispatcherInjected(): void
    {
        // Use reflection to verify the dispatcher was injected
        $reflection = new \ReflectionClass($this->orchestrator);
        $property = $reflection->getProperty('eventDispatcher');
        $dispatcher = $property->getValue($this->orchestrator);

        $this->assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $this->assertSame($this->eventDispatcher, $dispatcher);
    }

    /**
     * @group integration
     */
    public function testProcessCheckout_WithEmptyBasket_ReturnsFailure(): void
    {
        $basket = $this->createEmptyBasketMock();
        $user = $this->createValidUserMock();

        $result = $this->orchestrator->processCheckout(
            $basket,
            $user,
            'stripe_card',
            'pi_test_123'
        );

        $this->assertInstanceOf(CheckoutResult::class, $result);
        $this->assertFalse($result->isSuccess());
        $this->assertEquals('EMPTY_BASKET', $result->getErrorCode());
    }

    /**
     * @group integration
     */
    public function testProcessCheckout_WithInvalidUser_ReturnsFailure(): void
    {
        $basket = $this->createValidBasketMock();
        $user = $this->createInvalidUserMock();

        $result = $this->orchestrator->processCheckout(
            $basket,
            $user,
            'stripe_card',
            'pi_test_123'
        );

        $this->assertInstanceOf(CheckoutResult::class, $result);
        $this->assertFalse($result->isSuccess());
        $this->assertEquals('INVALID_USER', $result->getErrorCode());
    }

    /**
     * @group integration
     */
    public function testProcessCheckout_WithValidData_DispatchesEvent(): void
    {
        $basket = $this->createValidBasketMock();
        $user = $this->createValidUserMock();

        // This will dispatch PaymentInitiatedEvent
        // Without handlers registered, contract won't be created
        // So we expect CONTRACT_CREATION_FAILED (handlers not wired)
        $result = $this->orchestrator->processCheckout(
            $basket,
            $user,
            'stripe_card',
            'pi_test_123'
        );

        $this->assertInstanceOf(CheckoutResult::class, $result);
        // Without handlers, contract creation fails as expected
        $this->assertFalse($result->isSuccess());
        $this->assertEquals('CONTRACT_CREATION_FAILED', $result->getErrorCode());
    }

    /**
     * @group integration
     */
    public function testConfirmOrderCompletion_WithoutContractId_ReturnsFailure(): void
    {
        $result = $this->orchestrator->confirmOrderCompletion('order_123', null);

        $this->assertInstanceOf(OrderConfirmationResult::class, $result);
        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('No contract ID', $result->getErrorMessage());
    }

    /**
     * @group integration
     */
    public function testConfirmOrderCompletion_WithContractId_DispatchesEvent(): void
    {
        // This will dispatch OrderCompletedEvent
        // Without handlers, contract state won't change but no exception should occur
        $result = $this->orchestrator->confirmOrderCompletion('order_123', 'contract_456');

        $this->assertInstanceOf(OrderConfirmationResult::class, $result);
        // Success because event was dispatched (even if no handler processed it)
        $this->assertTrue($result->isSuccess());
    }

    /**
     * @group integration
     */
    public function testEventDispatcher_DispatchesPaymentInitiatedEvent(): void
    {
        $context = new EventContext([
            'userId' => 'test_user_123',
            'basket' => (object)['total' => 100.0],
        ]);

        $event = new PaymentInitiatedEvent(
            $context,
            'stripe_card',
            100.0,
            'EUR',
            'http://example.com/return',
            'http://example.com/cancel'
        );

        $dispatchedEvent = $this->eventDispatcher->dispatch($event);

        $this->assertSame($event, $dispatchedEvent);
        $this->assertEquals('stripe_card', $dispatchedEvent->getPaymentMethodId());
        $this->assertEquals(100.0, $dispatchedEvent->getAmount());
        $this->assertEquals('EUR', $dispatchedEvent->getCurrency());
    }

    /**
     * @group integration
     */
    public function testCheckoutResultValueObject_SuccessFactory(): void
    {
        $result = CheckoutResult::success('contract_abc_123');

        $this->assertTrue($result->isSuccess());
        $this->assertEquals('contract_abc_123', $result->getContractId());
        $this->assertNull($result->getErrorMessage());
        $this->assertNull($result->getErrorCode());
    }

    /**
     * @group integration
     */
    public function testCheckoutResultValueObject_FailureFactory(): void
    {
        $result = CheckoutResult::failure('Something went wrong', 'TEST_ERROR');

        $this->assertFalse($result->isSuccess());
        $this->assertNull($result->getContractId());
        $this->assertEquals('Something went wrong', $result->getErrorMessage());
        $this->assertEquals('TEST_ERROR', $result->getErrorCode());
    }

    /**
     * @group integration
     */
    public function testOrderConfirmationResultValueObject_SuccessWithCommittedState(): void
    {
        $result = OrderConfirmationResult::success(OrderConfirmationResult::STATE_COMMITTED);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(OrderConfirmationResult::STATE_COMMITTED, $result->getContractState());
        $this->assertTrue($result->isAwaitingPaymentConfirmation());
        $this->assertFalse($result->isFullyCompleted());
    }

    /**
     * @group integration
     */
    public function testOrderConfirmationResultValueObject_SuccessWithFulfilledState(): void
    {
        $result = OrderConfirmationResult::success(OrderConfirmationResult::STATE_FULFILLED);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(OrderConfirmationResult::STATE_FULFILLED, $result->getContractState());
        $this->assertFalse($result->isAwaitingPaymentConfirmation());
        $this->assertTrue($result->isFullyCompleted());
    }

    /**
     * @group integration
     */
    public function testEventDispatcher_WithCustomListener_CallsListener(): void
    {
        $listenerCalled = false;
        $capturedEvent = null;

        // Add a listener directly
        $this->eventDispatcher->addListener(
            PaymentInitiatedEvent::class,
            function (PaymentInitiatedEvent $event) use (&$listenerCalled, &$capturedEvent) {
                $listenerCalled = true;
                $capturedEvent = $event;
            }
        );

        $context = new EventContext(['test' => true]);
        $event = new PaymentInitiatedEvent(
            $context,
            'stripe_sepa',
            200.0,
            'USD',
            'http://example.com/return',
            'http://example.com/cancel'
        );

        $this->eventDispatcher->dispatch($event);

        $this->assertTrue($listenerCalled, 'Listener should have been called');
        $this->assertSame($event, $capturedEvent, 'Listener should receive the dispatched event');
    }

    /**
     * Creates an empty basket mock.
     */
    private function createEmptyBasketMock(): object
    {
        return new class {
            public function getProductsCount(): int
            {
                return 0;
            }

            public function getPaymentId(): string
            {
                return 'stripe_card';
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

    /**
     * Creates a valid basket mock with items.
     */
    private function createValidBasketMock(): object
    {
        return new class {
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
                return 150.0;
            }

            public function getBasketCurrency(): object
            {
                return (object)['name' => 'EUR'];
            }
        };
    }

    /**
     * Creates a valid user mock.
     */
    private function createValidUserMock(): object
    {
        return new class {
            public function getId(): string
            {
                return 'test_user_' . uniqid();
            }
        };
    }

    /**
     * Creates an invalid user mock (empty ID).
     */
    private function createInvalidUserMock(): object
    {
        return new class {
            public function getId(): string
            {
                return '';
            }
        };
    }
}
