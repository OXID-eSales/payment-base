<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\EventSystem\Event\Contract;

use PHPUnit\Framework\TestCase;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractCancelledEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractCancelledEventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractEventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;

final class ContractCancelledEventTest extends TestCase
{
    private PaymentContractInterface $contract;
    private EventContext $context;

    protected function setUp(): void
    {
        $this->contract = $this->createMock(PaymentContractInterface::class);
        $this->contract->method('getId')->willReturn('contract_123');
        $this->contract->method('getStateValue')->willReturn('cancelled');

        $this->context = new EventContext(['userId' => 'user_456']);
    }

    public function testImplementsContractCancelledEventInterface(): void
    {
        $event = new ContractCancelledEvent($this->contract, $this->context, 'User cancelled payment');

        $this->assertInstanceOf(ContractCancelledEventInterface::class, $event);
    }

    public function testImplementsContractEventInterface(): void
    {
        $event = new ContractCancelledEvent($this->contract, $this->context, 'User cancelled payment');

        $this->assertInstanceOf(ContractEventInterface::class, $event);
    }

    public function testImplementsEventInterface(): void
    {
        $event = new ContractCancelledEvent($this->contract, $this->context, 'User cancelled payment');

        $this->assertInstanceOf(EventInterface::class, $event);
    }

    public function testGetContract_ReturnsContract(): void
    {
        $event = new ContractCancelledEvent($this->contract, $this->context, 'User cancelled payment');

        $this->assertSame($this->contract, $event->getContract());
    }

    public function testGetContext_ReturnsContext(): void
    {
        $event = new ContractCancelledEvent($this->contract, $this->context, 'User cancelled payment');

        $this->assertSame($this->context, $event->getContext());
    }

    public function testGetContractId_ReturnsIdFromContract(): void
    {
        $event = new ContractCancelledEvent($this->contract, $this->context, 'User cancelled payment');

        $this->assertEquals('contract_123', $event->getContractId());
    }

    public function testGetContractState_ReturnsStateFromContract(): void
    {
        $event = new ContractCancelledEvent($this->contract, $this->context, 'User cancelled payment');

        $this->assertEquals('cancelled', $event->getContractState());
    }

    public function testGetReason_ReturnsReason(): void
    {
        $event = new ContractCancelledEvent($this->contract, $this->context, 'Payment declined');

        $this->assertEquals('Payment declined', $event->getReason());
    }

    public function testEvent_IsImmutable(): void
    {
        $event = new ContractCancelledEvent($this->contract, $this->context, 'User cancelled payment');

        $this->assertFalse(method_exists($event, 'setContract'));
        $this->assertFalse(method_exists($event, 'setContext'));
        $this->assertFalse(method_exists($event, 'setReason'));
    }

    public function testConstructor_UsesReadonlyProperties(): void
    {
        $event = new ContractCancelledEvent($this->contract, $this->context, 'User cancelled payment');

        $reflection = new \ReflectionClass($event);
        $this->assertTrue($reflection->getProperty('contract')->isReadOnly());
        $this->assertTrue($reflection->getProperty('context')->isReadOnly());
        $this->assertTrue($reflection->getProperty('reason')->isReadOnly());
    }
}
