<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\EventSystem\Handler;

use OxidEsales\PaymentComponent\Contract\ContractCondition;
use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\PaymentInitiatedEvent;
use OxidEsales\PaymentComponent\EventSystem\Handler\StockReservationHandler;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Service\StockManagementServiceInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;

/**
 * @covers \OxidEsales\PaymentComponent\EventSystem\Handler\StockReservationHandler
 */
class StockReservationHandlerTest extends TestCase
{
    private StockReservationHandler $handler;
    /** @var ContractRepositoryInterface&MockObject */
    private $contractRepository;
    /** @var StockManagementServiceInterface&MockObject */
    private $stockManagement;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->stockManagement = $this->createMock(StockManagementServiceInterface::class);

        $this->handler = new StockReservationHandler(
            $this->contractRepository,
            $this->stockManagement
        );
    }

    public function testReservesStockOnPaymentInitiation(): void
    {
        // Arrange: Basket with 2 products
        $contract = $this->createMock(PaymentContract::class);
        $context = new EventContext();
        $context->set('contract', $contract);
        $context->set('basket', [
            ['productId' => 'PROD-001', 'quantity' => 2],
            ['productId' => 'PROD-002', 'quantity' => 1],
        ]);

        $event = new PaymentInitiatedEvent($context, 'pm_test', 100.00, 'EUR', '/return', '/cancel');

        // Expect: Stock reserved for each product (15 minutes = 900 seconds)
        $callCount = 0;
        $this->stockManagement->expects($this->exactly(2))
            ->method('reserveStock')
            ->willReturnCallback(function ($productId, $quantity, $timeout) use (&$callCount) {
                $this->assertEquals(900, $timeout);
                if ($callCount === 0) {
                    $this->assertEquals('PROD-001', $productId);
                    $this->assertEquals(2, $quantity);
                } elseif ($callCount === 1) {
                    $this->assertEquals('PROD-002', $productId);
                    $this->assertEquals(1, $quantity);
                }
                $callCount++;
            });

        $contract->expects($this->once())
            ->method('fulfillCondition')
            ->with(
                ContractCondition::TYPE_STOCK_RESERVED,
                $this->callback(function ($data) {
                    return isset($data['reservedAt'])
                        && isset($data['products'])
                        && count($data['products']) === 2;
                })
            );

        $this->contractRepository->expects($this->once())
            ->method('save')
            ->with($contract);

        // Act
        $this->handler->handle($event);
    }

    public function testHandlesBasketWithSingleProduct(): void
    {
        // Arrange: Basket with 1 product
        $contract = $this->createMock(PaymentContract::class);
        $context = new EventContext();
        $context->set('contract', $contract);
        $context->set('basket', [
            ['productId' => 'PROD-003', 'quantity' => 5],
        ]);

        $event = new PaymentInitiatedEvent($context, 'pm_test', 50.00, 'EUR', '/return', '/cancel');

        // Expect: Stock reserved
        $this->stockManagement->expects($this->once())
            ->method('reserveStock')
            ->with('PROD-003', 5, 900);

        $contract->expects($this->once())
            ->method('fulfillCondition')
            ->with(ContractCondition::TYPE_STOCK_RESERVED, $this->isType('array'));

        $this->contractRepository->expects($this->once())
            ->method('save')
            ->with($contract);

        // Act
        $this->handler->handle($event);
    }

    public function testSkipsWhenNoBasketInContext(): void
    {
        // Arrange: Event without basket
        $contract = $this->createMock(PaymentContract::class);
        $context = new EventContext();
        $context->set('contract', $contract);

        $event = new PaymentInitiatedEvent($context, 'pm_test', 50.00, 'EUR', '/return', '/cancel');

        // Expect: No stock operations performed
        $this->stockManagement->expects($this->never())
            ->method('reserveStock');

        $contract->expects($this->never())
            ->method('fulfillCondition');

        $this->contractRepository->expects($this->never())
            ->method('save');

        // Act
        $this->handler->handle($event);
    }

    public function testSkipsWhenNoContractInContext(): void
    {
        // Arrange: Event without contract
        $context = new EventContext();
        $context->set('basket', [
            ['productId' => 'PROD-004', 'quantity' => 1],
        ]);

        $event = new PaymentInitiatedEvent($context, 'pm_test', 50.00, 'EUR', '/return', '/cancel');

        // Expect: No operations performed
        $this->stockManagement->expects($this->never())
            ->method('reserveStock');

        $this->contractRepository->expects($this->never())
            ->method('save');

        // Act
        $this->handler->handle($event);
    }

    public function testFailsContractWhenInsufficientStock(): void
    {
        // Arrange: Basket with insufficient stock
        $contract = $this->createMock(PaymentContract::class);
        $context = new EventContext();
        $context->set('contract', $contract);
        $context->set('basket', [
            ['productId' => 'PROD-005', 'quantity' => 100],
        ]);

        $event = new PaymentInitiatedEvent($context, 'pm_test', 500.00, 'EUR', '/return', '/cancel');

        // Expect: Stock reservation throws exception
        $this->stockManagement->expects($this->once())
            ->method('reserveStock')
            ->with('PROD-005', 100, 900)
            ->willThrowException(new RuntimeException('Insufficient stock for product PROD-005 (requested: 100)'));

        // Expect: Contract failed
        $contract->expects($this->once())
            ->method('fail')
            ->with($this->stringContains('Insufficient stock'));

        $contract->expects($this->never())
            ->method('fulfillCondition');

        $this->contractRepository->expects($this->once())
            ->method('save')
            ->with($contract);

        // Act
        $this->handler->handle($event);
    }

    public function testHandlerIgnoresNonPaymentInitiatedEvents(): void
    {
        // Arrange: Different event type
        $event = new \stdClass();

        // Act
        $this->handler->handle($event);

        // Assert: No interactions with dependencies
        $this->stockManagement->expects($this->never())->method('reserveStock');
        $this->contractRepository->expects($this->never())->method('save');
    }

    public function testHandlesEmptyBasket(): void
    {
        // Arrange: Empty basket array
        $contract = $this->createMock(PaymentContract::class);
        $context = new EventContext();
        $context->set('contract', $contract);
        $context->set('basket', []);

        $event = new PaymentInitiatedEvent($context, 'pm_test', 0.00, 'EUR', '/return', '/cancel');

        // Expect: No stock operations, but condition fulfilled
        $this->stockManagement->expects($this->never())
            ->method('reserveStock');

        $contract->expects($this->once())
            ->method('fulfillCondition')
            ->with(ContractCondition::TYPE_STOCK_RESERVED, $this->isType('array'));

        $this->contractRepository->expects($this->once())
            ->method('save')
            ->with($contract);

        // Act
        $this->handler->handle($event);
    }

    public function testReservesStockWithCorrectTimeout(): void
    {
        // Arrange: Basket with product
        $contract = $this->createMock(PaymentContract::class);
        $context = new EventContext();
        $context->set('contract', $contract);
        $context->set('basket', [
            ['productId' => 'PROD-006', 'quantity' => 3],
        ]);

        $event = new PaymentInitiatedEvent($context, 'pm_test', 75.00, 'EUR', '/return', '/cancel');

        // Expect: 900 seconds (15 minutes) timeout
        $this->stockManagement->expects($this->once())
            ->method('reserveStock')
            ->with('PROD-006', 3, 900);

        $contract->expects($this->once())
            ->method('fulfillCondition');

        $this->contractRepository->expects($this->once())
            ->method('save');

        // Act
        $this->handler->handle($event);
    }
}
