<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\EventSystem\Event\Contract;

use PHPUnit\Framework\TestCase;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractTransitionedToPendingEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractTransitionedToPendingEventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractEventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;

final class ContractTransitionedToPendingEventTest extends TestCase
{
    private PaymentContractInterface $contract;
    private EventContext $context;

    protected function setUp(): void
    {
        $this->contract = $this->createMock(PaymentContractInterface::class);
        $this->contract->method('getId')->willReturn('contract_123');
        $this->contract->method('getStateValue')->willReturn('pending');

        $this->context = new EventContext(['userId' => 'user_456']);
    }

    public function testImplementsContractTransitionedToPendingEventInterface(): void
    {
        $conditions = ['payment_authorized' => true];
        $event = new ContractTransitionedToPendingEvent($this->contract, $this->context, $conditions);

        $this->assertInstanceOf(ContractTransitionedToPendingEventInterface::class, $event);
    }

    public function testImplementsContractEventInterface(): void
    {
        $conditions = ['payment_authorized' => true];
        $event = new ContractTransitionedToPendingEvent($this->contract, $this->context, $conditions);

        $this->assertInstanceOf(ContractEventInterface::class, $event);
    }

    public function testImplementsEventInterface(): void
    {
        $conditions = ['payment_authorized' => true];
        $event = new ContractTransitionedToPendingEvent($this->contract, $this->context, $conditions);

        $this->assertInstanceOf(EventInterface::class, $event);
    }

    public function testGetContract_ReturnsContract(): void
    {
        $conditions = ['payment_authorized' => true];
        $event = new ContractTransitionedToPendingEvent($this->contract, $this->context, $conditions);

        $this->assertSame($this->contract, $event->getContract());
    }

    public function testGetContext_ReturnsContext(): void
    {
        $conditions = ['payment_authorized' => true];
        $event = new ContractTransitionedToPendingEvent($this->contract, $this->context, $conditions);

        $this->assertSame($this->context, $event->getContext());
    }

    public function testGetContractId_ReturnsIdFromContract(): void
    {
        $conditions = ['payment_authorized' => true];
        $event = new ContractTransitionedToPendingEvent($this->contract, $this->context, $conditions);

        $this->assertEquals('contract_123', $event->getContractId());
    }

    public function testGetContractState_ReturnsStateFromContract(): void
    {
        $conditions = ['payment_authorized' => true];
        $event = new ContractTransitionedToPendingEvent($this->contract, $this->context, $conditions);

        $this->assertEquals('pending', $event->getContractState());
    }

    public function testGetConditions_ReturnsConditions(): void
    {
        $conditions = ['payment_authorized' => true, 'stock_reserved' => false];
        $event = new ContractTransitionedToPendingEvent($this->contract, $this->context, $conditions);

        $this->assertEquals($conditions, $event->getConditions());
    }

    public function testEvent_IsImmutable(): void
    {
        $conditions = ['payment_authorized' => true];
        $event = new ContractTransitionedToPendingEvent($this->contract, $this->context, $conditions);

        $this->assertFalse(method_exists($event, 'setContract'));
        $this->assertFalse(method_exists($event, 'setContext'));
        $this->assertFalse(method_exists($event, 'setConditions'));
    }

    public function testConstructor_UsesReadonlyProperties(): void
    {
        $conditions = ['payment_authorized' => true];
        $event = new ContractTransitionedToPendingEvent($this->contract, $this->context, $conditions);

        $reflection = new \ReflectionClass($event);
        $contractProperty = $reflection->getProperty('contract');
        $contextProperty = $reflection->getProperty('context');
        $conditionsProperty = $reflection->getProperty('conditions');

        $this->assertTrue($contractProperty->isReadOnly());
        $this->assertTrue($contextProperty->isReadOnly());
        $this->assertTrue($conditionsProperty->isReadOnly());
    }
}
