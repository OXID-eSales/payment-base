<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\EventSystem\Event\Contract;

use PHPUnit\Framework\TestCase;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractFailedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractFailedEventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractEventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;

final class ContractFailedEventTest extends TestCase
{
    private PaymentContractInterface $contract;
    private EventContext $context;

    protected function setUp(): void
    {
        $this->contract = $this->createMock(PaymentContractInterface::class);
        $this->contract->method('getId')->willReturn('contract_123');
        $this->contract->method('getStateValue')->willReturn('failed');

        $this->context = new EventContext(['userId' => 'user_456']);
    }

    public function testImplementsContractFailedEventInterface(): void
    {
        $event = new ContractFailedEvent($this->contract, $this->context, 'PAYMENT_FAILED', 'Payment authorization failed');

        $this->assertInstanceOf(ContractFailedEventInterface::class, $event);
    }

    public function testImplementsContractEventInterface(): void
    {
        $event = new ContractFailedEvent($this->contract, $this->context, 'PAYMENT_FAILED', 'Payment authorization failed');

        $this->assertInstanceOf(ContractEventInterface::class, $event);
    }

    public function testImplementsEventInterface(): void
    {
        $event = new ContractFailedEvent($this->contract, $this->context, 'PAYMENT_FAILED', 'Payment authorization failed');

        $this->assertInstanceOf(EventInterface::class, $event);
    }

    public function testGetContract_ReturnsContract(): void
    {
        $event = new ContractFailedEvent($this->contract, $this->context, 'PAYMENT_FAILED', 'Payment authorization failed');

        $this->assertSame($this->contract, $event->getContract());
    }

    public function testGetContext_ReturnsContext(): void
    {
        $event = new ContractFailedEvent($this->contract, $this->context, 'PAYMENT_FAILED', 'Payment authorization failed');

        $this->assertSame($this->context, $event->getContext());
    }

    public function testGetContractId_ReturnsIdFromContract(): void
    {
        $event = new ContractFailedEvent($this->contract, $this->context, 'PAYMENT_FAILED', 'Payment authorization failed');

        $this->assertEquals('contract_123', $event->getContractId());
    }

    public function testGetContractState_ReturnsStateFromContract(): void
    {
        $event = new ContractFailedEvent($this->contract, $this->context, 'PAYMENT_FAILED', 'Payment authorization failed');

        $this->assertEquals('failed', $event->getContractState());
    }

    public function testGetErrorCode_ReturnsErrorCode(): void
    {
        $event = new ContractFailedEvent($this->contract, $this->context, 'INSUFFICIENT_FUNDS', 'Insufficient funds');

        $this->assertEquals('INSUFFICIENT_FUNDS', $event->getErrorCode());
    }

    public function testGetErrorMessage_ReturnsErrorMessage(): void
    {
        $event = new ContractFailedEvent($this->contract, $this->context, 'PAYMENT_FAILED', 'Card declined by issuer');

        $this->assertEquals('Card declined by issuer', $event->getErrorMessage());
    }

    public function testEvent_IsImmutable(): void
    {
        $event = new ContractFailedEvent($this->contract, $this->context, 'PAYMENT_FAILED', 'Payment authorization failed');

        $this->assertFalse(method_exists($event, 'setContract'));
        $this->assertFalse(method_exists($event, 'setContext'));
        $this->assertFalse(method_exists($event, 'setErrorCode'));
    }

    public function testConstructor_UsesReadonlyProperties(): void
    {
        $event = new ContractFailedEvent($this->contract, $this->context, 'PAYMENT_FAILED', 'Payment authorization failed');

        $reflection = new \ReflectionClass($event);
        $this->assertTrue($reflection->getProperty('contract')->isReadOnly());
        $this->assertTrue($reflection->getProperty('context')->isReadOnly());
        $this->assertTrue($reflection->getProperty('errorCode')->isReadOnly());
        $this->assertTrue($reflection->getProperty('errorMessage')->isReadOnly());
    }
}
