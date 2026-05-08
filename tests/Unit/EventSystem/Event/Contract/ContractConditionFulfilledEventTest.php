<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\EventSystem\Event\Contract;

use PHPUnit\Framework\TestCase;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractConditionFulfilledEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractConditionFulfilledEventInterface;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractEventInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

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
        $event = new ContractConditionFulfilledEvent($this->contract, $this->context, 'compliance_check', ['checkId' => '123']);

        $this->assertEquals('compliance_check', $event->getConditionType());
    }

    public function testGetConditionData_ReturnsConditionData(): void
    {
        $conditionData = ['checkId' => '123', 'status' => 'passed'];
        $event = new ContractConditionFulfilledEvent($this->contract, $this->context, 'compliance_check', $conditionData);

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
