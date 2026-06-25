<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Service;

use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractState;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractFulfilledEvent;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractFulfillmentService;
use OxidEsales\PaymentBase\Service\ContractFulfillmentServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for ContractFulfillmentService.
 *
 * Tests follow TDD principles and verify:
 * - LSP compliance (implements interface correctly)
 * - SRP (only handles contract fulfillment)
 * - DRY (single implementation for all fulfillment operations)
 */
#[CoversClass(\OxidEsales\PaymentBase\Service\ContractFulfillmentService::class)]
#[Group('sprint-18')]
class ContractFulfillmentServiceTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contractRepository;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private LoggerInterface&MockObject $logger;
    private ContractFulfillmentService $service;
    private BasketSnapshot $basketSnapshot;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new ContractFulfillmentService(
            $this->contractRepository,
            $this->eventDispatcher,
            $this->logger
        );

        $this->basketSnapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.0,
            'totalVat' => 16.0,
            'currency' => 'EUR',
        ]);
    }

    /**
     * LSP: Service implements interface
     */
    #[Test]
    public function implementsInterface(): void
    {
        $this->assertInstanceOf(
            ContractFulfillmentServiceInterface::class,
            $this->service
        );
    }

    /**
     * SRP: Fulfills committed contract
     */
    #[Test]
    public function fulfillsCommittedContract(): void
    {
        // Arrange
        $contract = $this->createContractInState('committed');

        $this->contractRepository
            ->expects($this->once())
            ->method('save')
            ->with($contract);

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(ContractFulfilledEvent::class));

        // Act
        $result = $this->service->fulfill($contract);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Guards: Already fulfilled contract returns false
     */
    #[Test]
    public function returnsFalseForAlreadyFulfilledContract(): void
    {
        // Arrange
        $contract = $this->createContractInState('fulfilled');

        $this->contractRepository
            ->expects($this->never())
            ->method('save');

        $this->eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        // Act
        $result = $this->service->fulfill($contract);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * Guards: Non-committed contract returns false
     */
    #[Test]
    public function returnsFalseForPendingContract(): void
    {
        // Arrange
        $contract = $this->createContractInState('pending');

        $this->contractRepository
            ->expects($this->never())
            ->method('save');

        $this->eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        // Act
        $result = $this->service->fulfill($contract);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * Guards: Draft contract returns false
     */
    #[Test]
    public function returnsFalseForDraftContract(): void
    {
        // Arrange
        $contract = $this->createContractInState('draft');

        $this->contractRepository
            ->expects($this->never())
            ->method('save');

        // Act
        $result = $this->service->fulfill($contract);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * SRP: Fulfills by provider order ID
     */
    #[Test]
    public function fulfillsByProviderOrderId(): void
    {
        // Arrange
        $providerOrderId = 'pi_test_123';
        $contract = $this->createContractInState('committed');

        $this->contractRepository
            ->expects($this->once())
            ->method('findByProviderOrderId')
            ->with($providerOrderId)
            ->willReturn($contract);

        $this->contractRepository
            ->expects($this->once())
            ->method('save')
            ->with($contract);

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch');

        // Act
        $result = $this->service->fulfillByProviderOrderId($providerOrderId);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Returns null when contract not found by provider order ID
     */
    #[Test]
    public function returnsNullWhenContractNotFoundByProviderOrderId(): void
    {
        // Arrange
        $providerOrderId = 'non_existent';

        $this->contractRepository
            ->expects($this->once())
            ->method('findByProviderOrderId')
            ->with($providerOrderId)
            ->willReturn(null);

        $this->contractRepository
            ->expects($this->never())
            ->method('save');

        // Act
        $result = $this->service->fulfillByProviderOrderId($providerOrderId);

        // Assert
        $this->assertNull($result);
    }

    /**
     * SRP: Fulfills by contract ID
     */
    #[Test]
    public function fulfillsByContractId(): void
    {
        // Arrange
        $contractId = 'contract-123';
        $contract = $this->createContractInState('committed');

        $this->contractRepository
            ->expects($this->once())
            ->method('findById')
            ->with($contractId)
            ->willReturn($contract);

        $this->contractRepository
            ->expects($this->once())
            ->method('save')
            ->with($contract);

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch');

        // Act
        $result = $this->service->fulfillByContractId($contractId);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Returns null when contract not found by contract ID
     */
    #[Test]
    public function returnsNullWhenContractNotFoundByContractId(): void
    {
        // Arrange
        $contractId = 'non_existent';

        $this->contractRepository
            ->expects($this->once())
            ->method('findById')
            ->with($contractId)
            ->willReturn(null);

        // Act
        $result = $this->service->fulfillByContractId($contractId);

        // Assert
        $this->assertNull($result);
    }

    /**
     * Logs successful fulfillment
     */
    #[Test]
    public function logsSuccessfulFulfillment(): void
    {
        // Arrange
        $contract = $this->createContractInState('committed');

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('Contract fulfilled'),
                $this->callback(function ($context) {
                    return isset($context['contract_id']);
                })
            );

        // Act
        $this->service->fulfill($contract);
    }

    /**
     * Logs when contract cannot be fulfilled
     */
    #[Test]
    public function logsWhenContractCannotBeFulfilled(): void
    {
        // Arrange
        $contract = $this->createContractInState('fulfilled');

        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->with(
                $this->stringContains('Cannot fulfill contract'),
                $this->callback(function ($context) {
                    return isset($context['contract_id']) && isset($context['state']);
                })
            );

        // Act
        $this->service->fulfill($contract);
    }

    /**
     * Event includes correct context data
     */
    #[Test]
    public function eventIncludesCorrectContextData(): void
    {
        // Arrange - contract is already committed with order-123
        $contract = $this->createContractInState('committed');

        $dispatchedEvent = null;
        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($event) use (&$dispatchedEvent) {
                $dispatchedEvent = $event;
                return $event;
            });

        // Act
        $this->service->fulfill($contract);

        // Assert
        $this->assertInstanceOf(ContractFulfilledEvent::class, $dispatchedEvent);
        $this->assertSame('order-123', $dispatchedEvent->getOrderId());
    }

    /**
     * Idempotent: Multiple fulfill calls on same contract only fulfill once
     */
    #[Test]
    public function isIdempotent(): void
    {
        // Arrange
        $contract = $this->createContractInState('committed');

        $this->contractRepository
            ->expects($this->once()) // Only saved once
            ->method('save');

        $this->eventDispatcher
            ->expects($this->once()) // Only dispatched once
            ->method('dispatch');

        // Act
        $result1 = $this->service->fulfill($contract);
        $result2 = $this->service->fulfill($contract); // Contract is now fulfilled

        // Assert
        $this->assertTrue($result1);
        $this->assertFalse($result2);
    }

    /**
     * Create a PaymentContract in specified state for testing.
     */
    private function createContractInState(string $state): PaymentContract
    {
        $contract = new PaymentContract(
            shopId: 1,
            userId: 'user-123',
            basketSnapshot: $this->basketSnapshot,
            id: 'contract-' . uniqid()
        );

        // Add condition for state transitions
        $contract->addCondition(new \OxidEsales\PaymentBase\Contract\ContractCondition(
            \OxidEsales\PaymentBase\Contract\ContractCondition::TYPE_PAYMENT_AUTHORIZED
        ));

        switch ($state) {
            case 'pending':
                $contract->setProvider('stripe', 'pi_test');
                $contract->transitionToNotFinished('order-123');
                $contract->transitionToPending();
                break;
            case 'committed':
                $contract->setProvider('stripe', 'pi_test');
                $contract->transitionToNotFinished('order-123');
                $contract->transitionToPending();
                $contract->fulfillCondition(\OxidEsales\PaymentBase\Contract\ContractCondition::TYPE_PAYMENT_AUTHORIZED);
                $contract->commitToOrder('order-123');
                break;
            case 'fulfilled':
                $contract->setProvider('stripe', 'pi_test');
                $contract->transitionToNotFinished('order-123');
                $contract->transitionToPending();
                $contract->fulfillCondition(\OxidEsales\PaymentBase\Contract\ContractCondition::TYPE_PAYMENT_AUTHORIZED);
                $contract->commitToOrder('order-123');
                $contract->fulfill();
                break;
            case 'draft':
            default:
                // Already in draft state
                break;
        }

        return $contract;
    }
}
