<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\EventSystem\Event\Contract;

use PHPUnit\Framework\TestCase;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractExpiredEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractExpiredEventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractEventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;

final class ContractExpiredEventTest extends TestCase
{
    private PaymentContractInterface $contract;
    private EventContext $context;

    protected function setUp(): void
    {
        $this->contract = $this->createMock(PaymentContractInterface::class);
        $this->contract->method('getId')->willReturn('contract_123');
        $this->contract->method('getStateValue')->willReturn('expired');

        $this->context = new EventContext(['userId' => 'user_456']);
    }

    public function testImplementsContractExpiredEventInterface(): void
    {
        $event = new ContractExpiredEvent($this->contract, $this->context, 1234567890);

        $this->assertInstanceOf(ContractExpiredEventInterface::class, $event);
    }

    public function testImplementsContractEventInterface(): void
    {
        $event = new ContractExpiredEvent($this->contract, $this->context, 1234567890);

        $this->assertInstanceOf(ContractEventInterface::class, $event);
    }

    public function testImplementsEventInterface(): void
    {
        $event = new ContractExpiredEvent($this->contract, $this->context, 1234567890);

        $this->assertInstanceOf(EventInterface::class, $event);
    }

    public function testGetContract_ReturnsContract(): void
    {
        $event = new ContractExpiredEvent($this->contract, $this->context, 1234567890);

        $this->assertSame($this->contract, $event->getContract());
    }

    public function testGetContext_ReturnsContext(): void
    {
        $event = new ContractExpiredEvent($this->contract, $this->context, 1234567890);

        $this->assertSame($this->context, $event->getContext());
    }

    public function testGetContractId_ReturnsIdFromContract(): void
    {
        $event = new ContractExpiredEvent($this->contract, $this->context, 1234567890);

        $this->assertEquals('contract_123', $event->getContractId());
    }

    public function testGetContractState_ReturnsStateFromContract(): void
    {
        $event = new ContractExpiredEvent($this->contract, $this->context, 1234567890);

        $this->assertEquals('expired', $event->getContractState());
    }

    public function testGetExpirationTime_ReturnsExpirationTime(): void
    {
        $expirationTime = 1609459200;
        $event = new ContractExpiredEvent($this->contract, $this->context, $expirationTime);

        $this->assertEquals($expirationTime, $event->getExpirationTime());
    }

    public function testEvent_IsImmutable(): void
    {
        $event = new ContractExpiredEvent($this->contract, $this->context, 1234567890);

        $this->assertFalse(method_exists($event, 'setContract'));
        $this->assertFalse(method_exists($event, 'setContext'));
        $this->assertFalse(method_exists($event, 'setExpirationTime'));
    }

    public function testConstructor_UsesReadonlyProperties(): void
    {
        $event = new ContractExpiredEvent($this->contract, $this->context, 1234567890);

        $reflection = new \ReflectionClass($event);
        $this->assertTrue($reflection->getProperty('contract')->isReadOnly());
        $this->assertTrue($reflection->getProperty('context')->isReadOnly());
        $this->assertTrue($reflection->getProperty('expirationTime')->isReadOnly());
    }
}
