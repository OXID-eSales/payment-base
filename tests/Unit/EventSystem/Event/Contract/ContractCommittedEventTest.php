<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\EventSystem\Event\Contract;

use PHPUnit\Framework\TestCase;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractCommittedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractCommittedEventInterface;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractEventInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

final class ContractCommittedEventTest extends TestCase
{
    private PaymentContractInterface $contract;
    private EventContext $context;

    protected function setUp(): void
    {
        $this->contract = $this->createMock(PaymentContractInterface::class);
        $this->contract->method('getId')->willReturn('contract_123');
        $this->contract->method('getStateValue')->willReturn('committed');

        $this->context = new EventContext(['userId' => 'user_456']);
    }

    public function testImplementsContractCommittedEventInterface(): void
    {
        $event = new ContractCommittedEvent($this->contract, $this->context, 'order_789');

        $this->assertInstanceOf(ContractCommittedEventInterface::class, $event);
    }

    public function testImplementsContractEventInterface(): void
    {
        $event = new ContractCommittedEvent($this->contract, $this->context, 'order_789');

        $this->assertInstanceOf(ContractEventInterface::class, $event);
    }

    public function testImplementsEventInterface(): void
    {
        $event = new ContractCommittedEvent($this->contract, $this->context, 'order_789');

        $this->assertInstanceOf(EventInterface::class, $event);
    }

    public function testGetContract_ReturnsContract(): void
    {
        $event = new ContractCommittedEvent($this->contract, $this->context, 'order_789');

        $this->assertSame($this->contract, $event->getContract());
    }

    public function testGetContext_ReturnsContext(): void
    {
        $event = new ContractCommittedEvent($this->contract, $this->context, 'order_789');

        $this->assertSame($this->context, $event->getContext());
    }

    public function testGetContractId_ReturnsIdFromContract(): void
    {
        $event = new ContractCommittedEvent($this->contract, $this->context, 'order_789');

        $this->assertEquals('contract_123', $event->getContractId());
    }

    public function testGetContractState_ReturnsStateFromContract(): void
    {
        $event = new ContractCommittedEvent($this->contract, $this->context, 'order_789');

        $this->assertEquals('committed', $event->getContractState());
    }

    public function testGetOrderId_ReturnsOrderId(): void
    {
        $event = new ContractCommittedEvent($this->contract, $this->context, 'order_789');

        $this->assertEquals('order_789', $event->getOrderId());
    }

    public function testEvent_IsImmutable(): void
    {
        $event = new ContractCommittedEvent($this->contract, $this->context, 'order_789');

        $this->assertFalse(method_exists($event, 'setContract'));
        $this->assertFalse(method_exists($event, 'setContext'));
        $this->assertFalse(method_exists($event, 'setOrderId'));
    }

    public function testConstructor_UsesReadonlyProperties(): void
    {
        $event = new ContractCommittedEvent($this->contract, $this->context, 'order_789');

        $reflection = new \ReflectionClass($event);
        $this->assertTrue($reflection->getProperty('contract')->isReadOnly());
        $this->assertTrue($reflection->getProperty('context')->isReadOnly());
        $this->assertTrue($reflection->getProperty('orderId')->isReadOnly());
    }
}
