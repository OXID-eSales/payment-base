<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\EventSystem\Event\Contract;

use PHPUnit\Framework\TestCase;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractReadyToCommitEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractReadyToCommitEventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractEventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;

final class ContractReadyToCommitEventTest extends TestCase
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

    public function testImplementsContractReadyToCommitEventInterface(): void
    {
        $providerData = ['authorizationId' => 'auth_123'];
        $event = new ContractReadyToCommitEvent($this->contract, $this->context, $providerData);

        $this->assertInstanceOf(ContractReadyToCommitEventInterface::class, $event);
    }

    public function testImplementsContractEventInterface(): void
    {
        $providerData = ['authorizationId' => 'auth_123'];
        $event = new ContractReadyToCommitEvent($this->contract, $this->context, $providerData);

        $this->assertInstanceOf(ContractEventInterface::class, $event);
    }

    public function testImplementsEventInterface(): void
    {
        $providerData = ['authorizationId' => 'auth_123'];
        $event = new ContractReadyToCommitEvent($this->contract, $this->context, $providerData);

        $this->assertInstanceOf(EventInterface::class, $event);
    }

    public function testGetContract_ReturnsContract(): void
    {
        $providerData = ['authorizationId' => 'auth_123'];
        $event = new ContractReadyToCommitEvent($this->contract, $this->context, $providerData);

        $this->assertSame($this->contract, $event->getContract());
    }

    public function testGetContext_ReturnsContext(): void
    {
        $providerData = ['authorizationId' => 'auth_123'];
        $event = new ContractReadyToCommitEvent($this->contract, $this->context, $providerData);

        $this->assertSame($this->context, $event->getContext());
    }

    public function testGetContractId_ReturnsIdFromContract(): void
    {
        $providerData = ['authorizationId' => 'auth_123'];
        $event = new ContractReadyToCommitEvent($this->contract, $this->context, $providerData);

        $this->assertEquals('contract_123', $event->getContractId());
    }

    public function testGetContractState_ReturnsStateFromContract(): void
    {
        $providerData = ['authorizationId' => 'auth_123'];
        $event = new ContractReadyToCommitEvent($this->contract, $this->context, $providerData);

        $this->assertEquals('pending', $event->getContractState());
    }

    public function testGetPaymentProviderData_ReturnsProviderData(): void
    {
        $providerData = ['authorizationId' => 'auth_123', 'captureId' => 'cap_456'];
        $event = new ContractReadyToCommitEvent($this->contract, $this->context, $providerData);

        $this->assertEquals($providerData, $event->getPaymentProviderData());
    }

    public function testEvent_IsImmutable(): void
    {
        $providerData = ['authorizationId' => 'auth_123'];
        $event = new ContractReadyToCommitEvent($this->contract, $this->context, $providerData);

        $this->assertFalse(method_exists($event, 'setContract'));
        $this->assertFalse(method_exists($event, 'setContext'));
        $this->assertFalse(method_exists($event, 'setPaymentProviderData'));
    }

    public function testConstructor_UsesReadonlyProperties(): void
    {
        $providerData = ['authorizationId' => 'auth_123'];
        $event = new ContractReadyToCommitEvent($this->contract, $this->context, $providerData);

        $reflection = new \ReflectionClass($event);
        $this->assertTrue($reflection->getProperty('contract')->isReadOnly());
        $this->assertTrue($reflection->getProperty('context')->isReadOnly());
        $this->assertTrue($reflection->getProperty('paymentProviderData')->isReadOnly());
    }
}
