<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\EventSystem\Handler;

use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractCancelledEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractExpiredEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractFailedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractFulfilledEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\Handler\StockReleaseHandler;
use OxidEsales\PaymentComponent\Service\Exception\StockReleaseException;
use OxidEsales\PaymentComponent\Service\StockServiceInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @covers \OxidEsales\PaymentComponent\EventSystem\Handler\StockReleaseHandler
 */
class StockReleaseHandlerTest extends TestCase
{
    private StockReleaseHandler $handler;
    /** @var StockServiceInterface&MockObject */
    private StockServiceInterface $stockService;

    protected function setUp(): void
    {
        $this->stockService = $this->createMock(StockServiceInterface::class);

        $this->handler = new StockReleaseHandler(
            $this->stockService,
            true // enabled by default
        );
    }

    // =========================================================================
    // Event handling tests - ContractCancelledEvent
    // =========================================================================

    public function testReleasesStockOnContractCancelled(): void
    {
        $contract = $this->createMockContract();
        $context = new EventContext();
        $event = new ContractCancelledEvent($contract, $context, 'User cancelled');

        $this->stockService->expects($this->once())
            ->method('releaseForContract')
            ->with($contract);

        $this->handler->handle($event);
    }

    // =========================================================================
    // Event handling tests - ContractExpiredEvent
    // =========================================================================

    public function testReleasesStockOnContractExpired(): void
    {
        $contract = $this->createMockContract();
        $context = new EventContext();
        $event = new ContractExpiredEvent($contract, $context, time());

        $this->stockService->expects($this->once())
            ->method('releaseForContract')
            ->with($contract);

        $this->handler->handle($event);
    }

    // =========================================================================
    // Event handling tests - ContractFailedEvent
    // =========================================================================

    public function testReleasesStockOnContractFailed(): void
    {
        $contract = $this->createMockContract();
        $context = new EventContext();
        $event = new ContractFailedEvent($contract, $context, 'payment_declined', 'Payment declined');

        $this->stockService->expects($this->once())
            ->method('releaseForContract')
            ->with($contract);

        $this->handler->handle($event);
    }

    // =========================================================================
    // Handler should NOT release on FULFILLED
    // =========================================================================

    public function testDoesNotReleaseStockOnContractFulfilled(): void
    {
        $contract = $this->createMockContract();
        $context = new EventContext();
        $event = new ContractFulfilledEvent($contract, $context, 'order123');

        $this->stockService->expects($this->never())
            ->method('releaseForContract');

        $this->handler->handle($event);
    }

    // =========================================================================
    // Handler ignores other events
    // =========================================================================

    public function testIgnoresUnrelatedEvents(): void
    {
        $event = new \stdClass();

        $this->stockService->expects($this->never())
            ->method('releaseForContract');

        $this->handler->handle($event);
    }

    // =========================================================================
    // Error handling - throw exception on failure (strict consistency)
    // =========================================================================

    public function testThrowsExceptionOnStockReleaseFailure(): void
    {
        $contract = $this->createMockContract();
        $contract->method('getId')->willReturn('contract123');

        $context = new EventContext();
        $event = new ContractCancelledEvent($contract, $context, 'User cancelled');

        $this->stockService->expects($this->once())
            ->method('releaseForContract')
            ->with($contract)
            ->willThrowException(new StockReleaseException('contract123', 'Database error'));

        $this->expectException(StockReleaseException::class);
        $this->expectExceptionMessage('contract123');

        $this->handler->handle($event);
    }

    // =========================================================================
    // Configuration tests
    // =========================================================================

    public function testSkipsWhenDisabled(): void
    {
        // Create handler with disabled flag
        $handler = new StockReleaseHandler(
            $this->stockService,
            false // disabled
        );

        $contract = $this->createMockContract();
        $context = new EventContext();
        $event = new ContractCancelledEvent($contract, $context, 'User cancelled');

        // When disabled, should not release stock
        $this->stockService->expects($this->never())
            ->method('releaseForContract');

        $handler->handle($event);
    }

    // =========================================================================
    // Helper methods
    // =========================================================================

    private function createMockContract(): PaymentContractInterface&MockObject
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract123');
        $contract->method('getMetadata')
            ->willReturnCallback(fn($key) => match ($key) {
                'stock_reserved' => true,
                'stock_reserved_items' => ['prod1' => 2],
                default => null,
            });

        return $contract;
    }
}
