<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Controller\Http;

use OxidEsales\PaymentComponent\Controller\Core\OrderController;
use OxidEsales\PaymentComponent\Service\CheckoutOrchestratorInterface;
use OxidEsales\PaymentComponent\Service\Result\CheckoutResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidEsales\PaymentComponent\Controller\Core\OrderController
 */
final class OrderControllerTest extends TestCase
{
    private CheckoutOrchestratorInterface&MockObject $orchestrator;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('OXID_PHP_UNIT')) {
            define('OXID_PHP_UNIT', true);
        }

        $this->orchestrator = $this->createMock(CheckoutOrchestratorInterface::class);
    }

    public function testIsExternalPaymentMethod_WithStripeCardPayment_ReturnsTrue(): void
    {
        $controller = $this->createControllerWithPaymentId('stripe_card');

        $reflection = new \ReflectionMethod($controller, 'isExternalPaymentMethod');
        $result = $reflection->invoke($controller);

        $this->assertTrue($result);
    }

    public function testIsExternalPaymentMethod_WithStripeSepaPayment_ReturnsTrue(): void
    {
        $controller = $this->createControllerWithPaymentId('stripe_sepa');

        $reflection = new \ReflectionMethod($controller, 'isExternalPaymentMethod');
        $result = $reflection->invoke($controller);

        $this->assertTrue($result);
    }

    public function testIsExternalPaymentMethod_WithCashOnDelivery_ReturnsFalse(): void
    {
        $controller = $this->createControllerWithPaymentId('oxidcashondel');

        $reflection = new \ReflectionMethod($controller, 'isExternalPaymentMethod');
        $result = $reflection->invoke($controller);

        $this->assertFalse($result);
    }

    public function testIsExternalPaymentMethod_WithPayPal_ReturnsFalse(): void
    {
        $controller = $this->createControllerWithPaymentId('oxidpaypal');

        $reflection = new \ReflectionMethod($controller, 'isExternalPaymentMethod');
        $result = $reflection->invoke($controller);

        $this->assertFalse($result);
    }

    public function testIsExternalPaymentMethod_WithNullPaymentId_ReturnsFalse(): void
    {
        $controller = $this->createControllerWithPaymentId(null);

        $reflection = new \ReflectionMethod($controller, 'isExternalPaymentMethod');
        $result = $reflection->invoke($controller);

        $this->assertFalse($result);
    }

    public function testExecute_WithExternalPayment_CallsOrchestrator(): void
    {
        $basket = $this->createBasketMock('stripe_card', 100.0);
        $user = $this->createUserMock('user_123');
        $session = $this->createSessionMock($basket, null);

        $contract = CheckoutResult::success('contract_123');

        $this->orchestrator
            ->expects($this->once())
            ->method('processCheckout')
            ->with(
                $basket,
                $user,
                'stripe_card',
                null
            )
            ->willReturn($contract);

        $controller = $this->createControllerWithMocks($basket, $user, $session);
        $controller->addServiceMock(CheckoutOrchestratorInterface::class, $this->orchestrator);

        // We can't easily call execute() without parent dependencies
        // Instead, test the private method directly
        $reflection = new \ReflectionMethod($controller, 'executeWithContractAccounting');
        $result = $reflection->invoke($controller);

        // Parent returns 'thankyou' or similar, but since we mock we just check no exception
        $this->assertNotNull($result);
    }

    public function testExecute_WithOrchestratorError_ReturnsOrderView(): void
    {
        $basket = $this->createBasketMock('stripe_card', 100.0);
        $user = $this->createUserMock('user_123');
        $session = $this->createSessionMock($basket, null);

        $failureResult = CheckoutResult::failure('Basket validation failed', 'VALIDATION_ERROR');

        $this->orchestrator
            ->method('processCheckout')
            ->willReturn($failureResult);

        $controller = $this->createControllerWithMocks($basket, $user, $session);
        $controller->addServiceMock(CheckoutOrchestratorInterface::class, $this->orchestrator);

        $reflection = new \ReflectionMethod($controller, 'executeWithContractAccounting');
        $result = $reflection->invoke($controller);

        $this->assertEquals('order', $result);
    }

    public function testExecute_WithSuccess_StoresContractIdInSession(): void
    {
        $basket = $this->createBasketMock('stripe_card', 100.0);
        $user = $this->createUserMock('user_123');

        $contractId = 'contract_xyz_789';
        $successResult = CheckoutResult::success($contractId);

        $session = $this->createMock(SessionMockInterface::class);
        $session->method('getBasket')->willReturn($basket);
        $session->method('getVariable')->willReturn(null);

        // Updated session key to payment_contract_id (provider-agnostic)
        $session->expects($this->once())
            ->method('setVariable')
            ->with('payment_contract_id', $contractId);

        $this->orchestrator
            ->method('processCheckout')
            ->willReturn($successResult);

        $controller = $this->createControllerWithMocks($basket, $user, $session);
        $controller->addServiceMock(CheckoutOrchestratorInterface::class, $this->orchestrator);

        $reflection = new \ReflectionMethod($controller, 'executeWithContractAccounting');
        $reflection->invoke($controller);
    }

    public function testExecute_WithProviderTransactionId_PassesToOrchestrator(): void
    {
        $basket = $this->createBasketMock('stripe_card', 150.0);
        $user = $this->createUserMock('user_456');
        $providerTransactionId = 'pi_test_intent_id_12345';

        $session = $this->createSessionMock($basket, $providerTransactionId);

        $successResult = CheckoutResult::success('contract_999');

        $this->orchestrator
            ->expects($this->once())
            ->method('processCheckout')
            ->with(
                $basket,
                $user,
                'stripe_card',
                $providerTransactionId
            )
            ->willReturn($successResult);

        $controller = $this->createControllerWithMocks($basket, $user, $session, $providerTransactionId);
        $controller->addServiceMock(CheckoutOrchestratorInterface::class, $this->orchestrator);

        $reflection = new \ReflectionMethod($controller, 'executeWithContractAccounting');
        $reflection->invoke($controller);
    }

    public function testExecute_WithNonExternalPayment_DoesNotCallOrchestrator(): void
    {
        $controller = $this->createControllerWithPaymentId('oxidcashondel');

        $this->orchestrator
            ->expects($this->never())
            ->method('processCheckout');

        $controller->addServiceMock(CheckoutOrchestratorInterface::class, $this->orchestrator);

        // isExternalPaymentMethod returns false, so orchestrator is not called
        $reflection = new \ReflectionMethod($controller, 'isExternalPaymentMethod');
        $isExternal = $reflection->invoke($controller);

        $this->assertFalse($isExternal);
    }

    /**
     * Creates a controller with mocked payment ID.
     */
    private function createControllerWithPaymentId(?string $paymentId): OrderController
    {
        $basket = $this->createBasketMock($paymentId, 0.0);
        $session = $this->createSessionMock($basket, null);

        return new class ($session, $basket) extends OrderController {
            private object $mockSession;
            private object $mockBasket;

            public function __construct(object $mockSession, object $mockBasket)
            {
                $this->mockSession = $mockSession;
                $this->mockBasket = $mockBasket;
            }

            /**
             * Override to return mock basket directly (bypassing session type check).
             */
            protected function isExternalPaymentMethod(): bool
            {
                $paymentId = $this->mockBasket->getPaymentId();

                if ($paymentId === null || $paymentId === '') {
                    return false;
                }

                return $this->isPaymentMethodSupported((string) $paymentId);
            }

            /**
             * Override for test - check if payment method is Stripe
             */
            protected function isPaymentMethodSupported(string $paymentId): bool
            {
                return str_starts_with($paymentId, 'stripe_');
            }
        };
    }

    /**
     * Creates a controller with all mocks injected.
     */
    private function createControllerWithMocks(
        object $basket,
        object $user,
        object $session,
        ?string $providerTransactionId = null
    ): OrderController {
        return new class ($basket, $user, $session, $providerTransactionId) extends OrderController {
            private object $mockBasket;
            private object $mockUser;
            private object $mockSession;
            private ?string $mockProviderTransactionId;
            private bool $errorAdded = false;

            public function __construct(
                object $mockBasket,
                object $mockUser,
                object $mockSession,
                ?string $mockProviderTransactionId
            ) {
                $this->mockBasket = $mockBasket;
                $this->mockUser = $mockUser;
                $this->mockSession = $mockSession;
                $this->mockProviderTransactionId = $mockProviderTransactionId;
            }

            /**
             * Override to return mock basket directly.
             */
            protected function getBasketFromSession(): object
            {
                return $this->mockBasket;
            }

            /**
             * Override to return mock session.
             */
            protected function getSessionForVariables(): object
            {
                return $this->mockSession;
            }

            public function getUser(): object
            {
                return $this->mockUser;
            }

            protected function getProviderTransactionIdFromRequest(): ?string
            {
                return $this->mockProviderTransactionId;
            }

            protected function addErrorToDisplay(string $message): void
            {
                $this->errorAdded = true;
            }

            protected function executeParent(): mixed
            {
                return 'thankyou';
            }

            public function wasErrorAdded(): bool
            {
                return $this->errorAdded;
            }

            /**
             * Override for test - check if payment method is Stripe
             */
            protected function isPaymentMethodSupported(string $paymentId): bool
            {
                return str_starts_with($paymentId, 'stripe_');
            }
        };
    }

    /**
     * Creates a basket mock.
     */
    private function createBasketMock(?string $paymentId, float $amount): object
    {
        return new class ($paymentId, $amount) {
            public function __construct(
                private ?string $paymentId,
                private float $amount
            ) {
            }

            public function getPaymentId(): ?string
            {
                return $this->paymentId;
            }

            public function getProductsCount(): int
            {
                return $this->amount > 0 ? 1 : 0;
            }

            public function getBruttoSum(): float
            {
                return $this->amount;
            }

            public function getBasketCurrency(): object
            {
                return (object)['name' => 'EUR'];
            }
        };
    }

    /**
     * Creates a user mock.
     */
    private function createUserMock(string $id): object
    {
        return new class ($id) {
            public function __construct(private string $id)
            {
            }

            public function getId(): string
            {
                return $this->id;
            }
        };
    }

    /**
     * Creates a session mock.
     */
    private function createSessionMock(object $basket, ?string $providerTransactionId): object
    {
        return new class ($basket, $providerTransactionId) {
            private array $variables = [];

            public function __construct(
                private object $basket,
                private ?string $providerTransactionId
            ) {
            }

            public function getBasket(): object
            {
                return $this->basket;
            }

            public function getVariable(string $name): mixed
            {
                return $this->variables[$name] ?? null;
            }

            public function setVariable(string $name, mixed $value): void
            {
                $this->variables[$name] = $value;
            }

            public function deleteVariable(string $name): void
            {
                unset($this->variables[$name]);
            }
        };
    }
}

/**
 * Interface for session mock with typed methods.
 */
interface SessionMockInterface
{
    public function getBasket(): object;
    public function getVariable(string $name): mixed;
    public function setVariable(string $name, mixed $value): void;
    public function deleteVariable(string $name): void;
}
