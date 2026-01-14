<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use OxidEsales\PaymentComponent\Service\CheckoutOrchestrator;
use OxidEsales\PaymentComponent\Service\CheckoutOrchestratorInterface;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\PaymentInitiatedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\OrderCompletedEvent;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\Service\Result\OrderConfirmationResult;
use Psr\Log\NullLogger;

class CheckoutOrchestratorTest extends TestCase
{
    private CheckoutOrchestrator $orchestrator;
    private EventDispatcherInterface $eventDispatcher;

    protected function setUp(): void
    {
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->orchestrator = new CheckoutOrchestrator(
            $this->eventDispatcher,
            new NullLogger()
        );
    }

    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(CheckoutOrchestratorInterface::class, $this->orchestrator);
    }

    public function testProcessCheckout_WithValidBasket_DispatchesEvent(): void
    {
        // Arrange
        $basket = $this->createBasketMock(itemCount: 2, totalGross: 100.0);
        $user = $this->createUserMock(id: 'user_123');
        $contract = $this->createContractMock(id: 'contract_456');

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(PaymentInitiatedEvent::class))
            ->willReturnCallback(function (PaymentInitiatedEvent $event) use ($contract) {
                $event->getContext()->setContract($contract);
                return $event;
            });

        // Act
        $result = $this->orchestrator->processCheckout(
            $basket,
            $user,
            'stripe_card',
            'pi_test_123'
        );

        // Assert
        $this->assertTrue($result->isSuccess());
        $this->assertEquals('contract_456', $result->getContractId());
    }

    public function testProcessCheckout_WithEmptyBasket_ReturnsFailure(): void
    {
        // Arrange
        $basket = $this->createBasketMock(itemCount: 0);
        $user = $this->createUserMock(id: 'user_123');

        // EventDispatcher should not be called
        $this->eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        // Act
        $result = $this->orchestrator->processCheckout(
            $basket,
            $user,
            'stripe_card'
        );

        // Assert
        $this->assertFalse($result->isSuccess());
        $this->assertEquals('EMPTY_BASKET', $result->getErrorCode());
        $this->assertStringContainsString('Basket is empty', $result->getErrorMessage());
    }

    public function testProcessCheckout_WithInvalidUser_ReturnsFailure(): void
    {
        // Arrange
        $basket = $this->createBasketMock(itemCount: 1, totalGross: 50.0);
        $user = $this->createUserMock(id: ''); // Empty ID

        // EventDispatcher should not be called
        $this->eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        // Act
        $result = $this->orchestrator->processCheckout(
            $basket,
            $user,
            'stripe_card'
        );

        // Assert
        $this->assertFalse($result->isSuccess());
        $this->assertEquals('INVALID_USER', $result->getErrorCode());
    }

    public function testProcessCheckout_WhenContractNotCreated_ReturnsFailure(): void
    {
        // Arrange
        $basket = $this->createBasketMock(itemCount: 1, totalGross: 50.0);
        $user = $this->createUserMock(id: 'user_123');

        // Event dispatched but contract not set in context
        $this->eventDispatcher
            ->method('dispatch')
            ->willReturnArgument(0);

        // Act
        $result = $this->orchestrator->processCheckout(
            $basket,
            $user,
            'stripe_card'
        );

        // Assert
        $this->assertFalse($result->isSuccess());
        $this->assertEquals('CONTRACT_CREATION_FAILED', $result->getErrorCode());
    }

    public function testProcessCheckout_WhenExceptionThrown_ReturnsFailure(): void
    {
        // Arrange
        $basket = $this->createBasketMock(itemCount: 1, totalGross: 50.0);
        $user = $this->createUserMock(id: 'user_123');

        $this->eventDispatcher
            ->method('dispatch')
            ->willThrowException(new \RuntimeException('Database error'));

        // Act
        $result = $this->orchestrator->processCheckout(
            $basket,
            $user,
            'stripe_card'
        );

        // Assert
        $this->assertFalse($result->isSuccess());
        $this->assertEquals('PROCESSING_ERROR', $result->getErrorCode());
        $this->assertStringContainsString('Database error', $result->getErrorMessage());
    }

    public function testConfirmOrderCompletion_WithValidContract_ReturnsSuccess(): void
    {
        // Arrange
        $contract = $this->createContractMock(id: 'contract_123', state: 'COMMITTED');

        $this->eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(function ($event) use ($contract) {
                $event->getContext()->setContract($contract);
                return $event;
            });

        // Act
        $result = $this->orchestrator->confirmOrderCompletion('order_456', 'contract_123');

        // Assert
        $this->assertTrue($result->isSuccess());
        $this->assertEquals('COMMITTED', $result->getContractState());
        $this->assertTrue($result->isAwaitingPaymentConfirmation());
    }

    public function testConfirmOrderCompletion_WithoutContractId_ReturnsFailure(): void
    {
        // Act
        $result = $this->orchestrator->confirmOrderCompletion('order_456', null);

        // Assert
        $this->assertFalse($result->isSuccess());
        $this->assertEquals('No contract ID provided', $result->getErrorMessage());
        $this->assertEquals(OrderConfirmationResult::STATE_FAILED, $result->getContractState());
    }

    public function testConfirmOrderCompletion_WhenExceptionThrown_ReturnsFailure(): void
    {
        // Arrange
        $this->eventDispatcher
            ->method('dispatch')
            ->willThrowException(new \RuntimeException('Contract not found'));

        // Act
        $result = $this->orchestrator->confirmOrderCompletion('order_456', 'contract_123');

        // Assert
        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('Contract not found', $result->getErrorMessage());
    }

    public function testConfirmOrderCompletion_WithFulfilledState_ReturnsFullyCompleted(): void
    {
        // Arrange
        $contract = $this->createContractMock(id: 'contract_123', state: 'FULFILLED');

        $this->eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(function ($event) use ($contract) {
                $event->getContext()->setContract($contract);
                return $event;
            });

        // Act
        $result = $this->orchestrator->confirmOrderCompletion('order_456', 'contract_123');

        // Assert
        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->isFullyCompleted());
        $this->assertFalse($result->isAwaitingPaymentConfirmation());
    }

    // Helper methods
    private function createBasketMock(int $itemCount, float $totalGross = 0.0): object
    {
        $basket = new class($itemCount, $totalGross) {
            public function __construct(private int $count, private float $total)
            {
            }
            public function getProductsCount(): int
            {
                return $this->count;
            }
            public function getBruttoSum(): float
            {
                return $this->total;
            }
            public function getBasketCurrency(): object
            {
                return (object)['name' => 'EUR'];
            }
        };
        return $basket;
    }

    private function createUserMock(string $id): object
    {
        $user = new class($id) {
            public function __construct(private string $id)
            {
            }
            public function getId(): string
            {
                return $this->id;
            }
        };
        return $user;
    }

    private function createContractMock(string $id, string $state = 'PENDING'): PaymentContractInterface
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn($id);
        $contract->method('getStateValue')->willReturn($state);
        return $contract;
    }
}
