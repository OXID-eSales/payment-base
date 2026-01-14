<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\EventSystem\Handler;

use OxidEsales\PaymentComponent\Contract\ContractCondition;
use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractCancelledEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractFailedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\Handler\StockReleaseHandler;
use OxidEsales\PaymentComponent\Service\StockManagementServiceInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @covers \OxidEsales\PaymentComponent\EventSystem\Handler\StockReleaseHandler
 */
class StockReleaseHandlerTest extends TestCase
{
    private StockReleaseHandler $handler;
    /** @var StockManagementServiceInterface&MockObject */
    private $stockManagement;

    protected function setUp(): void
    {
        $this->stockManagement = $this->createMock(StockManagementServiceInterface::class);

        $this->handler = new StockReleaseHandler(
            $this->stockManagement
        );
    }

    public function testReleasesStockOnContractFailed(): void
    {
        // Arrange: Contract with reserved stock
        $contract = $this->createMock(PaymentContract::class);
        $stockCondition = $this->createMock(ContractCondition::class);

        $stockCondition->expects($this->once())
            ->method('getType')
            ->willReturn(ContractCondition::TYPE_STOCK_RESERVED);

        $stockCondition->expects($this->once())
            ->method('getData')
            ->willReturn([
                'products' => [
                    ['productId' => 'PROD-001', 'quantity' => 2],
                    ['productId' => 'PROD-002', 'quantity' => 1],
                ],
            ]);

        $contract->expects($this->once())
            ->method('getConditions')
            ->willReturn([$stockCondition]);

        $context = new EventContext();

        $event = new ContractFailedEvent($contract, $context, 'payment_declined', 'Payment declined');

        // Expect: Stock released for each product
        $callCount = 0;
        $this->stockManagement->expects($this->exactly(2))
            ->method('releaseStock')
            ->willReturnCallback(function ($productId, $quantity) use (&$callCount) {
                if ($callCount === 0) {
                    $this->assertEquals('PROD-001', $productId);
                    $this->assertEquals(2, $quantity);
                } elseif ($callCount === 1) {
                    $this->assertEquals('PROD-002', $productId);
                    $this->assertEquals(1, $quantity);
                }
                $callCount++;
            });

        // Act
        $this->handler->handle($event);
    }

    public function testReleasesStockOnContractCancelled(): void
    {
        // Arrange: Cancelled contract with reserved stock
        $contract = $this->createMock(PaymentContract::class);
        $stockCondition = $this->createMock(ContractCondition::class);

        $stockCondition->expects($this->once())
            ->method('getType')
            ->willReturn(ContractCondition::TYPE_STOCK_RESERVED);

        $stockCondition->expects($this->once())
            ->method('getData')
            ->willReturn([
                'products' => [
                    ['productId' => 'PROD-003', 'quantity' => 5],
                ],
            ]);

        $contract->expects($this->once())
            ->method('getConditions')
            ->willReturn([$stockCondition]);

        $context = new EventContext();

        $event = new ContractCancelledEvent($contract, $context, 'User cancelled payment');

        // Expect: Stock released
        $this->stockManagement->expects($this->once())
            ->method('releaseStock')
            ->with('PROD-003', 5);

        // Act
        $this->handler->handle($event);
    }

    public function testSkipsWhenNoContractInContext(): void
    {
        // This test is no longer valid since ContractFailedEvent requires a contract
        // Skipping this test as the event cannot be constructed without a contract
        $this->markTestSkipped('Event constructor requires contract, cannot test null contract scenario');
    }

    public function testSkipsWhenNoStockReservationCondition(): void
    {
        // Arrange: Contract without stock reservation
        $contract = $this->createMock(PaymentContract::class);

        $contract->expects($this->once())
            ->method('getConditions')
            ->willReturn([]);

        $context = new EventContext();

        $event = new ContractFailedEvent($contract, $context, 'payment_error', 'Payment error');

        // Expect: No stock operations
        $this->stockManagement->expects($this->never())
            ->method('releaseStock');

        // Act
        $this->handler->handle($event);
    }

    public function testSkipsWhenStockConditionHasNoProducts(): void
    {
        // Arrange: Stock condition without products data
        $contract = $this->createMock(PaymentContract::class);
        $stockCondition = $this->createMock(ContractCondition::class);

        $stockCondition->expects($this->once())
            ->method('getType')
            ->willReturn(ContractCondition::TYPE_STOCK_RESERVED);

        $stockCondition->expects($this->once())
            ->method('getData')
            ->willReturn([]);

        $contract->expects($this->once())
            ->method('getConditions')
            ->willReturn([$stockCondition]);

        $context = new EventContext();

        $event = new ContractCancelledEvent($contract, $context, 'Cancelled');

        // Expect: No stock operations
        $this->stockManagement->expects($this->never())
            ->method('releaseStock');

        // Act
        $this->handler->handle($event);
    }

    public function testHandlerIgnoresOtherEventTypes(): void
    {
        // Arrange: Different event type
        $event = new \stdClass();

        // Act
        $this->handler->handle($event);

        // Assert: No interactions with dependencies
        $this->stockManagement->expects($this->never())->method('releaseStock');
    }

    public function testReleasesStockForMultipleProducts(): void
    {
        // Arrange: Contract with multiple products
        $contract = $this->createMock(PaymentContract::class);
        $stockCondition = $this->createMock(ContractCondition::class);

        $stockCondition->expects($this->once())
            ->method('getType')
            ->willReturn(ContractCondition::TYPE_STOCK_RESERVED);

        $stockCondition->expects($this->once())
            ->method('getData')
            ->willReturn([
                'products' => [
                    ['productId' => 'PROD-010', 'quantity' => 3],
                    ['productId' => 'PROD-011', 'quantity' => 7],
                    ['productId' => 'PROD-012', 'quantity' => 1],
                ],
            ]);

        $contract->expects($this->once())
            ->method('getConditions')
            ->willReturn([$stockCondition]);

        $context = new EventContext();

        $event = new ContractFailedEvent($contract, $context, 'timeout', 'Timeout');

        // Expect: All products released
        $callCount = 0;
        $this->stockManagement->expects($this->exactly(3))
            ->method('releaseStock')
            ->willReturnCallback(function ($productId, $quantity) use (&$callCount) {
                if ($callCount === 0) {
                    $this->assertEquals('PROD-010', $productId);
                    $this->assertEquals(3, $quantity);
                } elseif ($callCount === 1) {
                    $this->assertEquals('PROD-011', $productId);
                    $this->assertEquals(7, $quantity);
                } elseif ($callCount === 2) {
                    $this->assertEquals('PROD-012', $productId);
                    $this->assertEquals(1, $quantity);
                }
                $callCount++;
            });

        // Act
        $this->handler->handle($event);
    }

    public function testHandlesEmptyProductsArray(): void
    {
        // Arrange: Stock condition with empty products array
        $contract = $this->createMock(PaymentContract::class);
        $stockCondition = $this->createMock(ContractCondition::class);

        $stockCondition->expects($this->once())
            ->method('getType')
            ->willReturn(ContractCondition::TYPE_STOCK_RESERVED);

        $stockCondition->expects($this->once())
            ->method('getData')
            ->willReturn(['products' => []]);

        $contract->expects($this->once())
            ->method('getConditions')
            ->willReturn([$stockCondition]);

        $context = new EventContext();

        $event = new ContractCancelledEvent($contract, $context, 'User action');

        // Expect: No stock operations (empty array)
        $this->stockManagement->expects($this->never())
            ->method('releaseStock');

        // Act
        $this->handler->handle($event);
    }
}
