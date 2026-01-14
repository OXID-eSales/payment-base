<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Controller\Http;

use OxidEsales\PaymentComponent\Controller\Core\ThankyouController;
use OxidEsales\PaymentComponent\Service\CheckoutOrchestratorInterface;
use OxidEsales\PaymentComponent\Service\Result\OrderConfirmationResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidEsales\PaymentComponent\Controller\Core\ThankyouController
 */
final class ThankyouControllerTest extends TestCase
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

    public function testRender_WithContractId_CallsOrchestrator(): void
    {
        $contractId = 'contract_456';
        $orderId = 'order_123';
        $result = OrderConfirmationResult::success(OrderConfirmationResult::STATE_COMMITTED);

        $this->orchestrator
            ->expects($this->once())
            ->method('confirmOrderCompletion')
            ->with($orderId, $contractId)
            ->willReturn($result);

        $order = $this->createOrderMock($orderId);
        $session = $this->createSessionMock($contractId);

        $controller = $this->createControllerWithMocks($order, $session);
        $controller->addServiceMock(CheckoutOrchestratorInterface::class, $this->orchestrator);

        $viewName = $controller->render();

        $this->assertEquals('thankyou', $viewName);
    }

    public function testRender_WithoutContractId_SkipsOrchestrator(): void
    {
        $this->orchestrator
            ->expects($this->never())
            ->method('confirmOrderCompletion');

        $order = $this->createOrderMock('order_123');
        $session = $this->createSessionMock(null); // No contract ID

        $controller = $this->createControllerWithMocks($order, $session);
        $controller->addServiceMock(CheckoutOrchestratorInterface::class, $this->orchestrator);

        $viewName = $controller->render();

        $this->assertEquals('thankyou', $viewName);
    }

    public function testRender_WithSuccess_ClearsSessionVariables(): void
    {
        $contractId = 'contract_789';
        $orderId = 'order_456';
        $result = OrderConfirmationResult::success(OrderConfirmationResult::STATE_COMMITTED);

        $this->orchestrator
            ->method('confirmOrderCompletion')
            ->willReturn($result);

        $order = $this->createOrderMock($orderId);
        $session = $this->createSessionMock($contractId);

        // Track session variable deletions
        $deletedVariables = [];
        $session->onDelete = function (string $name) use (&$deletedVariables) {
            $deletedVariables[] = $name;
        };

        $controller = $this->createControllerWithMocks($order, $session);
        $controller->addServiceMock(CheckoutOrchestratorInterface::class, $this->orchestrator);

        $controller->render();

        $this->assertContains('payment_contract_id', $deletedVariables);
        $this->assertContains('payment_provider_transaction_id', $deletedVariables);
    }

    public function testRender_WithError_DoesNotClearSession(): void
    {
        $contractId = 'contract_789';
        $orderId = 'order_456';
        $result = OrderConfirmationResult::failure('Contract not found');

        $this->orchestrator
            ->method('confirmOrderCompletion')
            ->willReturn($result);

        $order = $this->createOrderMock($orderId);
        $session = $this->createSessionMock($contractId);

        // Track session variable deletions
        $deletedVariables = [];
        $session->onDelete = function (string $name) use (&$deletedVariables) {
            $deletedVariables[] = $name;
        };

        $controller = $this->createControllerWithMocks($order, $session);
        $controller->addServiceMock(CheckoutOrchestratorInterface::class, $this->orchestrator);

        $controller->render();

        // Session should NOT be cleared on failure
        $this->assertNotContains('payment_contract_id', $deletedVariables);
    }

    public function testRender_WithException_DoesNotBreakPage(): void
    {
        $contractId = 'contract_error';
        $orderId = 'order_error';

        $this->orchestrator
            ->method('confirmOrderCompletion')
            ->willThrowException(new \RuntimeException('Database connection lost'));

        $order = $this->createOrderMock($orderId);
        $session = $this->createSessionMock($contractId);

        $controller = $this->createControllerWithMocks($order, $session);
        $controller->addServiceMock(CheckoutOrchestratorInterface::class, $this->orchestrator);

        // Should not throw - page must render
        $viewName = $controller->render();

        $this->assertEquals('thankyou', $viewName);
    }

    public function testRender_WithoutOrder_SkipsConfirmation(): void
    {
        $contractId = 'contract_no_order';

        $this->orchestrator
            ->expects($this->never())
            ->method('confirmOrderCompletion');

        $session = $this->createSessionMock($contractId);

        // Controller returns null order
        $controller = $this->createControllerWithMocks(null, $session);
        $controller->addServiceMock(CheckoutOrchestratorInterface::class, $this->orchestrator);

        $viewName = $controller->render();

        $this->assertEquals('thankyou', $viewName);
    }

    public function testRender_WithAwaitingState_LogsInfo(): void
    {
        $contractId = 'contract_awaiting';
        $orderId = 'order_awaiting';
        $result = OrderConfirmationResult::success(OrderConfirmationResult::STATE_COMMITTED);

        $this->orchestrator
            ->method('confirmOrderCompletion')
            ->willReturn($result);

        $order = $this->createOrderMock($orderId);
        $session = $this->createSessionMock($contractId);

        $loggedMessages = [];
        $controller = $this->createControllerWithMocks($order, $session, function ($msg) use (&$loggedMessages) {
            $loggedMessages[] = $msg;
        });
        $controller->addServiceMock(CheckoutOrchestratorInterface::class, $this->orchestrator);

        $controller->render();

        $this->assertNotEmpty($loggedMessages);
        $this->assertStringContainsString('awaiting', $loggedMessages[0]);
    }

    public function testRender_WithFulfilledState_LogsFullyCompleted(): void
    {
        $contractId = 'contract_fulfilled';
        $orderId = 'order_fulfilled';
        $result = OrderConfirmationResult::success(OrderConfirmationResult::STATE_FULFILLED);

        $this->orchestrator
            ->method('confirmOrderCompletion')
            ->willReturn($result);

        $order = $this->createOrderMock($orderId);
        $session = $this->createSessionMock($contractId);

        $loggedMessages = [];
        $controller = $this->createControllerWithMocks($order, $session, function ($msg) use (&$loggedMessages) {
            $loggedMessages[] = $msg;
        });
        $controller->addServiceMock(CheckoutOrchestratorInterface::class, $this->orchestrator);

        $controller->render();

        $this->assertNotEmpty($loggedMessages);
        $this->assertStringContainsString('fully completed', $loggedMessages[0]);
    }

    /**
     * Creates a controller with mocks injected.
     */
    private function createControllerWithMocks(
        ?object $order,
        object $session,
        ?callable $logCallback = null
    ): ThankyouController {
        return new class ($order, $session, $logCallback) extends ThankyouController {
            private ?object $mockOrder;
            private object $mockSession;
            /** @var callable|null */
            private $logCallback;
            private array $loggedErrors = [];
            private array $loggedInfo = [];

            public function __construct(?object $mockOrder, object $mockSession, ?callable $logCallback = null)
            {
                $this->mockOrder = $mockOrder;
                $this->mockSession = $mockSession;
                $this->logCallback = $logCallback;
            }

            public function getOrder(): ?object
            {
                return $this->mockOrder;
            }

            protected function getSession(): object
            {
                return $this->mockSession;
            }

            protected function renderParent(): string
            {
                return 'thankyou';
            }

            protected function logInfo(string $message, array $context = []): void
            {
                $this->loggedInfo[] = $message;
                if ($this->logCallback) {
                    ($this->logCallback)($message);
                }
            }

            protected function logError(string $message, array $context = []): void
            {
                $this->loggedErrors[] = $message;
            }

            public function getLoggedErrors(): array
            {
                return $this->loggedErrors;
            }

            public function getLoggedInfo(): array
            {
                return $this->loggedInfo;
            }
        };
    }

    /**
     * Creates an order mock.
     */
    private function createOrderMock(string $orderId): object
    {
        return new class ($orderId) {
            public function __construct(private string $orderId)
            {
            }

            public function getId(): string
            {
                return $this->orderId;
            }
        };
    }

    /**
     * Creates a session mock.
     */
    private function createSessionMock(?string $contractId): object
    {
        return new class ($contractId) {
            private array $variables = [];
            /** @var callable|null */
            public $onDelete = null;

            public function __construct(?string $contractId)
            {
                if ($contractId !== null) {
                    $this->variables['payment_contract_id'] = $contractId;
                }
                $this->variables['payment_provider_transaction_id'] = 'pi_test_123';
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
                if ($this->onDelete !== null) {
                    ($this->onDelete)($name);
                }
            }
        };
    }
}
