<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout;

use OxidEsales\PaymentBase\Checkout\OpenCheckoutAttemptOrder;
use OxidEsales\PaymentBase\Checkout\OpenCheckoutAttemptRegistryInterface;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * OPC-175 / S2a — "which order does the attempt this session has open hold?"
 * Only an attempt that can still be paid answers; a settled or ended one
 * does not.
 */
final class OpenCheckoutAttemptOrderTest extends TestCase
{
    private OpenCheckoutAttemptRegistryInterface&MockObject $registry;
    private ContractRepositoryInterface&MockObject $contracts;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(OpenCheckoutAttemptRegistryInterface::class);
        $this->contracts = $this->createMock(ContractRepositoryInterface::class);
    }

    public function testOrderId_NoOpenAttempt_IsNullWithoutADatabaseRead(): void
    {
        $this->registry->method('peek')->willReturn(null);
        $this->contracts->expects($this->never())->method('findById');

        self::assertNull($this->service()->orderId());
    }

    public function testOrderId_PendingAttempt_IsItsOrderId(): void
    {
        $this->registry->method('peek')->willReturn('ctr-1');
        $this->contracts->method('findById')->with('ctr-1')->willReturn($this->pendingContract());

        self::assertSame('order-1', $this->service()->orderId());
    }

    public function testOrderId_CommittedAttempt_IsNull(): void
    {
        $contract = $this->pendingContract();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder('order-1');
        $this->registry->method('peek')->willReturn('ctr-1');
        $this->contracts->method('findById')->willReturn($contract);

        self::assertNull($this->service()->orderId());
    }

    public function testOrderId_CancelledAttempt_IsNull(): void
    {
        $contract = $this->pendingContract();
        $contract->cancel('checkout_retry');
        $this->registry->method('peek')->willReturn('ctr-1');
        $this->contracts->method('findById')->willReturn($contract);

        self::assertNull($this->service()->orderId());
    }

    public function testOrderId_UnknownContract_IsNull(): void
    {
        $this->registry->method('peek')->willReturn('gone');
        $this->contracts->method('findById')->willReturn(null);

        self::assertNull($this->service()->orderId());
    }

    public function testOrderId_AttemptWithoutOrder_IsNull(): void
    {
        $this->registry->method('peek')->willReturn('ctr-1');
        $this->contracts->method('findById')->willReturn($this->draftContract());

        self::assertNull($this->service()->orderId());
    }

    private function service(): OpenCheckoutAttemptOrder
    {
        return new OpenCheckoutAttemptOrder($this->registry, $this->contracts);
    }

    private function draftContract(): PaymentContract
    {
        $contract = new PaymentContract(1, 'user-1', BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 42.0, 'totalNet' => 35.29, 'totalVat' => 6.71, 'currency' => 'EUR',
        ]), 'ctr-1');
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));

        return $contract;
    }

    private function pendingContract(): PaymentContract
    {
        $contract = $this->draftContract();
        $contract->transitionToNotFinished('order-1');
        $contract->transitionToPending();

        return $contract;
    }
}
