<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\EventSystem\Handler;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractFulfilledEvent;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\Handler\OrderPaymentCompletedHandler;
use OxidEsales\PaymentBase\Service\OrderPaymentStateServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * Sprint 147 (AW) Story 5: the handler had no behaviour test (audit T-13).
 */
final class OrderPaymentCompletedHandlerTest extends TestCase
{
    private OrderPaymentStateServiceInterface&MockObject $state;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->state = $this->createMock(OrderPaymentStateServiceInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testHandle_WhenTheEventIsOfAnotherType_DoesNothing(): void
    {
        $this->state->expects(self::never())->method('markOrderAsPaid');

        $this->handler()->handle(new stdClass());
    }

    public function testHandle_WhenTheOrderIdIsAbsent_WarnsAndWritesNothing(): void
    {
        $this->state->expects(self::never())->method('markOrderAsPaid');
        $this->logger->expects(self::once())->method('warning');

        $this->handler()->handle($this->fulfilled(orderId: null));
    }

    public function testHandle_WhenTheOrderIdIsEmpty_WritesNothing(): void
    {
        $this->state->expects(self::never())->method('markOrderAsPaid');

        $this->handler()->handle($this->fulfilled(orderId: ''));
    }

    public function testHandle_WhenTheContractIsFulfilled_MarksTheOrderAsPaidWithTheProviderOrderId(): void
    {
        $this->state->expects(self::once())->method('markOrderAsPaid')->with('order-1', 'pi_1')->willReturn(true);
        $this->logger->expects(self::once())->method('info');

        $this->handler()->handle($this->fulfilled(orderId: 'order-1'));
    }

    public function testHandle_WhenTheOrderIsPaidAlready_WritesNoInfoLine(): void
    {
        $this->state->method('markOrderAsPaid')->willReturn(false);
        $this->logger->expects(self::never())->method('info');

        $this->handler()->handle($this->fulfilled(orderId: 'order-1'));
    }

    /**
     * Sprint 147 (AW) Story 2: the service throws when the database refuses the write.
     */
    public function testHandle_WhenThePaidMarkFails_LetsTheFailureThrough(): void
    {
        $this->state->method('markOrderAsPaid')->willThrowException(new \RuntimeException('Failed to update order paid mark'));

        $this->expectException(\RuntimeException::class);

        $this->handler()->handle($this->fulfilled(orderId: 'order-1'));
    }

    private function handler(): OrderPaymentCompletedHandler
    {
        return new OrderPaymentCompletedHandler($this->state, $this->logger);
    }

    private function fulfilled(?string $orderId): ContractFulfilledEvent
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract-1');
        $contract->method('getOrderId')->willReturn($orderId);
        $contract->method('getProviderOrderId')->willReturn('pi_1');

        return new ContractFulfilledEvent($contract, new EventContext(), (string) $orderId);
    }
}
