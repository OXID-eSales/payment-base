<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\EventSystem\Event\Payment;

use PHPUnit\Framework\TestCase;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\OrderCreatedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\OrderCreatedEventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\PaymentEventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;

final class OrderCreatedEventTest extends TestCase
{
    private EventContext $context;

    protected function setUp(): void
    {
        $this->context = new EventContext(['userId' => 'user_456']);
    }

    public function testImplementsOrderCreatedEventInterface(): void
    {
        $event = new OrderCreatedEvent($this->context, 'order_123', 'contract_456');

        $this->assertInstanceOf(OrderCreatedEventInterface::class, $event);
    }

    public function testImplementsPaymentEventInterface(): void
    {
        $event = new OrderCreatedEvent($this->context, 'order_123', 'contract_456');

        $this->assertInstanceOf(PaymentEventInterface::class, $event);
    }

    public function testImplementsEventInterface(): void
    {
        $event = new OrderCreatedEvent($this->context, 'order_123', 'contract_456');

        $this->assertInstanceOf(EventInterface::class, $event);
    }

    public function testGetContext_ReturnsContext(): void
    {
        $event = new OrderCreatedEvent($this->context, 'order_123', 'contract_456');

        $this->assertSame($this->context, $event->getContext());
    }

    public function testGetOrderId_ReturnsOrderId(): void
    {
        $event = new OrderCreatedEvent($this->context, 'order_xyz789', 'contract_456');

        $this->assertEquals('order_xyz789', $event->getOrderId());
    }

    public function testGetContractId_ReturnsContractId(): void
    {
        $event = new OrderCreatedEvent($this->context, 'order_123', 'contract_abc123');

        $this->assertEquals('contract_abc123', $event->getContractId());
    }

    public function testEvent_IsImmutable(): void
    {
        $event = new OrderCreatedEvent($this->context, 'order_123', 'contract_456');

        $this->assertFalse(method_exists($event, 'setContext'));
        $this->assertFalse(method_exists($event, 'setOrderId'));
        $this->assertFalse(method_exists($event, 'setContractId'));
    }

    public function testConstructor_UsesReadonlyProperties(): void
    {
        $event = new OrderCreatedEvent($this->context, 'order_123', 'contract_456');

        $reflection = new \ReflectionClass($event);
        $this->assertTrue($reflection->getProperty('context')->isReadOnly());
        $this->assertTrue($reflection->getProperty('orderId')->isReadOnly());
        $this->assertTrue($reflection->getProperty('contractId')->isReadOnly());
    }
}
