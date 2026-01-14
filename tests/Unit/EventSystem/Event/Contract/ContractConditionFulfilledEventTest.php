<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\EventSystem\Event\Contract;

use PHPUnit\Framework\TestCase;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractConditionFulfilledEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractConditionFulfilledEventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractEventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;

final class ContractConditionFulfilledEventTest extends TestCase
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

    public function testImplementsContractConditionFulfilledEventInterface(): void
    {
        $event = new ContractConditionFulfilledEvent($this->contract, $this->context, 'payment_authorized', ['amount' => 100.00]);

        $this->assertInstanceOf(ContractConditionFulfilledEventInterface::class, $event);
    }

    public function testImplementsContractEventInterface(): void
    {
        $event = new ContractConditionFulfilledEvent($this->contract, $this->context, 'payment_authorized', ['amount' => 100.00]);

        $this->assertInstanceOf(ContractEventInterface::class, $event);
    }

    public function testImplementsEventInterface(): void
    {
        $event = new ContractConditionFulfilledEvent($this->contract, $this->context, 'payment_authorized', ['amount' => 100.00]);

        $this->assertInstanceOf(EventInterface::class, $event);
    }

    public function testGetContract_ReturnsContract(): void
    {
        $event = new ContractConditionFulfilledEvent($this->contract, $this->context, 'payment_authorized', ['amount' => 100.00]);

        $this->assertSame($this->contract, $event->getContract());
    }

    public function testGetContext_ReturnsContext(): void
    {
        $event = new ContractConditionFulfilledEvent($this->contract, $this->context, 'payment_authorized', ['amount' => 100.00]);

        $this->assertSame($this->context, $event->getContext());
    }

    public function testGetContractId_ReturnsIdFromContract(): void
    {
        $event = new ContractConditionFulfilledEvent($this->contract, $this->context, 'payment_authorized', ['amount' => 100.00]);

        $this->assertEquals('contract_123', $event->getContractId());
    }

    public function testGetContractState_ReturnsStateFromContract(): void
    {
        $event = new ContractConditionFulfilledEvent($this->contract, $this->context, 'payment_authorized', ['amount' => 100.00]);

        $this->assertEquals('pending', $event->getContractState());
    }

    public function testGetConditionType_ReturnsConditionType(): void
    {
        $event = new ContractConditionFulfilledEvent($this->contract, $this->context, 'stock_reserved', ['productId' => '123']);

        $this->assertEquals('stock_reserved', $event->getConditionType());
    }

    public function testGetConditionData_ReturnsConditionData(): void
    {
        $conditionData = ['productId' => '123', 'quantity' => 5];
        $event = new ContractConditionFulfilledEvent($this->contract, $this->context, 'stock_reserved', $conditionData);

        $this->assertEquals($conditionData, $event->getConditionData());
    }

    public function testEvent_IsImmutable(): void
    {
        $event = new ContractConditionFulfilledEvent($this->contract, $this->context, 'payment_authorized', ['amount' => 100.00]);

        $this->assertFalse(method_exists($event, 'setContract'));
        $this->assertFalse(method_exists($event, 'setContext'));
        $this->assertFalse(method_exists($event, 'setConditionType'));
    }

    public function testConstructor_UsesReadonlyProperties(): void
    {
        $event = new ContractConditionFulfilledEvent($this->contract, $this->context, 'payment_authorized', ['amount' => 100.00]);

        $reflection = new \ReflectionClass($event);
        $this->assertTrue($reflection->getProperty('contract')->isReadOnly());
        $this->assertTrue($reflection->getProperty('context')->isReadOnly());
        $this->assertTrue($reflection->getProperty('conditionType')->isReadOnly());
        $this->assertTrue($reflection->getProperty('conditionData')->isReadOnly());
    }
}
