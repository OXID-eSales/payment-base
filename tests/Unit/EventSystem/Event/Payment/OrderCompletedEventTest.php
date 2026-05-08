<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\EventSystem\Event\Payment;

use PHPUnit\Framework\TestCase;
use OxidEsales\PaymentBase\EventSystem\Event\Payment\OrderCompletedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Payment\OrderCompletedEventInterface;
use OxidEsales\PaymentBase\EventSystem\Event\Payment\PaymentEventInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;

final class OrderCompletedEventTest extends TestCase
{
    private EventContext $context;

    protected function setUp(): void
    {
        $this->context = new EventContext(['userId' => 'user_456']);
    }

    public function testImplementsOrderCompletedEventInterface(): void
    {
        $event = new OrderCompletedEvent($this->context, 'order_123', 'provider_order_456');

        $this->assertInstanceOf(OrderCompletedEventInterface::class, $event);
    }

    public function testImplementsPaymentEventInterface(): void
    {
        $event = new OrderCompletedEvent($this->context, 'order_123', 'provider_order_456');

        $this->assertInstanceOf(PaymentEventInterface::class, $event);
    }

    public function testImplementsEventInterface(): void
    {
        $event = new OrderCompletedEvent($this->context, 'order_123', 'provider_order_456');

        $this->assertInstanceOf(EventInterface::class, $event);
    }

    public function testGetContext_ReturnsContext(): void
    {
        $event = new OrderCompletedEvent($this->context, 'order_123', 'provider_order_456');

        $this->assertSame($this->context, $event->getContext());
    }

    public function testGetOrderId_ReturnsOrderId(): void
    {
        $event = new OrderCompletedEvent($this->context, 'order_xyz789', 'provider_order_456');

        $this->assertEquals('order_xyz789', $event->getOrderId());
    }

    public function testGetProviderOrderId_ReturnsProviderOrderId(): void
    {
        $event = new OrderCompletedEvent($this->context, 'order_123', 'stripe_order_abc');

        $this->assertEquals('stripe_order_abc', $event->getProviderOrderId());
    }

    public function testEvent_IsImmutable(): void
    {
        $event = new OrderCompletedEvent($this->context, 'order_123', 'provider_order_456');

        $this->assertFalse(method_exists($event, 'setContext'));
        $this->assertFalse(method_exists($event, 'setOrderId'));
        $this->assertFalse(method_exists($event, 'setProviderOrderId'));
    }

    public function testConstructor_UsesReadonlyProperties(): void
    {
        $event = new OrderCompletedEvent($this->context, 'order_123', 'provider_order_456');

        $reflection = new \ReflectionClass($event);
        $this->assertTrue($reflection->getProperty('context')->isReadOnly());
        $this->assertTrue($reflection->getProperty('orderId')->isReadOnly());
        $this->assertTrue($reflection->getProperty('providerOrderId')->isReadOnly());
    }
}
