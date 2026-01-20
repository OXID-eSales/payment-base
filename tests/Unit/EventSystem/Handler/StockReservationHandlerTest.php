<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\EventSystem\Handler;

use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\Contract\ContractCondition;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractCreatedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\Handler\StockReservationHandler;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Service\Exception\InsufficientStockException;
use OxidEsales\PaymentComponent\Service\StockServiceInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @covers \OxidEsales\PaymentComponent\EventSystem\Handler\StockReservationHandler
 */
class StockReservationHandlerTest extends TestCase
{
    private StockReservationHandler $handler;
    /** @var ContractRepositoryInterface&MockObject */
    private ContractRepositoryInterface $contractRepository;
    /** @var StockServiceInterface&MockObject */
    private StockServiceInterface $stockService;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->stockService = $this->createMock(StockServiceInterface::class);

        $this->handler = new StockReservationHandler(
            $this->contractRepository,
            $this->stockService,
            true // enabled by default
        );
    }

    // =========================================================================
    // Event handling tests
    // =========================================================================

    public function testHandlesContractCreatedEvent(): void
    {
        $this->assertEquals(ContractCreatedEvent::class, StockReservationHandler::getHandledEventClass());
    }

    public function testReservesStockOnContractCreated(): void
    {
        $contract = $this->createMockContract();
        $context = new EventContext();
        $event = new ContractCreatedEvent($contract, $context);

        $this->stockService->expects($this->once())
            ->method('reserveForContract')
            ->with($contract);

        $contract->expects($this->once())
            ->method('fulfillCondition')
            ->with(
                ContractCondition::TYPE_STOCK_RESERVED,
                $this->callback(fn($data) => isset($data['reservedAt']))
            );

        $this->contractRepository->expects($this->once())
            ->method('save')
            ->with($contract);

        $this->handler->handle($event);
    }

    public function testFailsContractOnInsufficientStock(): void
    {
        $contract = $this->createMockContract();
        $context = new EventContext();
        $event = new ContractCreatedEvent($contract, $context);

        $exception = new InsufficientStockException('prod1', 5, 2);

        $this->stockService->expects($this->once())
            ->method('reserveForContract')
            ->with($contract)
            ->willThrowException($exception);

        $contract->expects($this->once())
            ->method('fail')
            ->with($this->stringContains('Insufficient stock'));

        $contract->expects($this->never())
            ->method('fulfillCondition');

        $this->contractRepository->expects($this->once())
            ->method('save')
            ->with($contract);

        $this->handler->handle($event);
    }

    // =========================================================================
    // Handler ignores other events
    // =========================================================================

    public function testIgnoresNonContractCreatedEvents(): void
    {
        $event = new \stdClass();

        $this->stockService->expects($this->never())
            ->method('reserveForContract');

        $this->contractRepository->expects($this->never())
            ->method('save');

        $this->handler->handle($event);
    }

    // =========================================================================
    // Configuration tests
    // =========================================================================

    public function testSkipsWhenDisabled(): void
    {
        // Create handler with disabled flag
        $handler = new StockReservationHandler(
            $this->contractRepository,
            $this->stockService,
            false // disabled
        );

        $contract = $this->createMockContract();
        $context = new EventContext();
        $event = new ContractCreatedEvent($contract, $context);

        // When disabled, should immediately fulfill condition without reserving stock
        $this->stockService->expects($this->never())
            ->method('reserveForContract');

        $contract->expects($this->once())
            ->method('fulfillCondition')
            ->with(
                ContractCondition::TYPE_STOCK_RESERVED,
                $this->callback(fn($data) => $data['skipped'] === true)
            );

        $this->contractRepository->expects($this->once())
            ->method('save')
            ->with($contract);

        $handler->handle($event);
    }

    // =========================================================================
    // Helper methods
    // =========================================================================

    private function createMockContract(): PaymentContractInterface&MockObject
    {
        $basketSnapshot = BasketSnapshot::fromArray([
            'items' => [
                ['productId' => 'prod1', 'quantity' => 2],
            ],
            'totalGross' => 100.0,
            'totalNet' => 84.0,
            'totalVat' => 16.0,
            'currency' => 'EUR',
        ]);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getBasketSnapshot')->willReturn($basketSnapshot);
        $contract->method('getId')->willReturn('contract123');

        return $contract;
    }
}
