<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\EventSystem\Event\Contract;

use PHPUnit\Framework\TestCase;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractCreatedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractCreatedEventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractEventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;

final class ContractCreatedEventTest extends TestCase
{
    private PaymentContractInterface $contract;
    private EventContext $context;

    protected function setUp(): void
    {
        $this->contract = $this->createMock(PaymentContractInterface::class);
        $this->contract->method('getId')->willReturn('contract_123');
        $this->contract->method('getStateValue')->willReturn('draft');

        $this->context = new EventContext(['userId' => 'user_456']);
    }

    public function testImplementsContractCreatedEventInterface(): void
    {
        $event = new ContractCreatedEvent($this->contract, $this->context);

        $this->assertInstanceOf(ContractCreatedEventInterface::class, $event);
    }

    public function testImplementsContractEventInterface(): void
    {
        $event = new ContractCreatedEvent($this->contract, $this->context);

        $this->assertInstanceOf(ContractEventInterface::class, $event);
    }

    public function testImplementsEventInterface(): void
    {
        $event = new ContractCreatedEvent($this->contract, $this->context);

        $this->assertInstanceOf(EventInterface::class, $event);
    }

    public function testConstruct_CreatesEvent(): void
    {
        $event = new ContractCreatedEvent($this->contract, $this->context);

        $this->assertInstanceOf(ContractCreatedEvent::class, $event);
    }

    public function testGetContract_ReturnsContract(): void
    {
        $event = new ContractCreatedEvent($this->contract, $this->context);

        $this->assertSame($this->contract, $event->getContract());
    }

    public function testGetContext_ReturnsContext(): void
    {
        $event = new ContractCreatedEvent($this->contract, $this->context);

        $this->assertSame($this->context, $event->getContext());
    }

    public function testGetContractId_ReturnsIdFromContract(): void
    {
        $event = new ContractCreatedEvent($this->contract, $this->context);

        $this->assertEquals('contract_123', $event->getContractId());
    }

    public function testGetContractState_ReturnsStateFromContract(): void
    {
        $event = new ContractCreatedEvent($this->contract, $this->context);

        $this->assertEquals('draft', $event->getContractState());
    }

    public function testEvent_IsImmutable(): void
    {
        $event = new ContractCreatedEvent($this->contract, $this->context);

        $this->assertFalse(method_exists($event, 'setContract'));
        $this->assertFalse(method_exists($event, 'setContext'));
    }

    public function testConstructor_UsesReadonlyProperties(): void
    {
        $event = new ContractCreatedEvent($this->contract, $this->context);

        $reflection = new \ReflectionClass($event);
        $contractProperty = $reflection->getProperty('contract');
        $contextProperty = $reflection->getProperty('context');

        $this->assertTrue($contractProperty->isReadOnly());
        $this->assertTrue($contextProperty->isReadOnly());
    }
}
