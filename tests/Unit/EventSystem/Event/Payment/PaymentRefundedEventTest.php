<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\EventSystem\Event\Payment;

use PHPUnit\Framework\TestCase;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\PaymentRefundedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\PaymentRefundedEventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\PaymentEventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;

final class PaymentRefundedEventTest extends TestCase
{
    private EventContext $context;

    protected function setUp(): void
    {
        $this->context = new EventContext(['userId' => 'user_456']);
    }

    public function testImplementsPaymentRefundedEventInterface(): void
    {
        $event = new PaymentRefundedEvent($this->context, 'refund_123', 'provider_order_456', 100.50, 'EUR', 'order_789');

        $this->assertInstanceOf(PaymentRefundedEventInterface::class, $event);
    }

    public function testImplementsPaymentEventInterface(): void
    {
        $event = new PaymentRefundedEvent($this->context, 'refund_123', 'provider_order_456', 100.50, 'EUR', 'order_789');

        $this->assertInstanceOf(PaymentEventInterface::class, $event);
    }

    public function testImplementsEventInterface(): void
    {
        $event = new PaymentRefundedEvent($this->context, 'refund_123', 'provider_order_456', 100.50, 'EUR', 'order_789');

        $this->assertInstanceOf(EventInterface::class, $event);
    }

    public function testGetContext_ReturnsContext(): void
    {
        $event = new PaymentRefundedEvent($this->context, 'refund_123', 'provider_order_456', 100.50, 'EUR', 'order_789');

        $this->assertSame($this->context, $event->getContext());
    }

    public function testGetRefundId_ReturnsRefundId(): void
    {
        $event = new PaymentRefundedEvent($this->context, 'refund_xyz789', 'provider_order_456', 100.50, 'EUR', 'order_789');

        $this->assertEquals('refund_xyz789', $event->getRefundId());
    }

    public function testGetProviderOrderId_ReturnsProviderOrderId(): void
    {
        $event = new PaymentRefundedEvent($this->context, 'refund_123', 'stripe_order_abc', 100.50, 'EUR', 'order_789');

        $this->assertEquals('stripe_order_abc', $event->getProviderOrderId());
    }

    public function testGetAmount_ReturnsAmount(): void
    {
        $event = new PaymentRefundedEvent($this->context, 'refund_123', 'provider_order_456', 250.75, 'EUR', 'order_789');

        $this->assertEquals(250.75, $event->getAmount());
    }

    public function testGetCurrency_ReturnsCurrency(): void
    {
        $event = new PaymentRefundedEvent($this->context, 'refund_123', 'provider_order_456', 100.50, 'USD', 'order_789');

        $this->assertEquals('USD', $event->getCurrency());
    }

    public function testGetOrderId_ReturnsOrderId(): void
    {
        $event = new PaymentRefundedEvent($this->context, 'refund_123', 'provider_order_456', 100.50, 'EUR', 'order_abc123');

        $this->assertEquals('order_abc123', $event->getOrderId());
    }

    public function testEvent_IsImmutable(): void
    {
        $event = new PaymentRefundedEvent($this->context, 'refund_123', 'provider_order_456', 100.50, 'EUR', 'order_789');

        $this->assertFalse(method_exists($event, 'setContext'));
        $this->assertFalse(method_exists($event, 'setRefundId'));
        $this->assertFalse(method_exists($event, 'setAmount'));
    }

    public function testConstructor_UsesReadonlyProperties(): void
    {
        $event = new PaymentRefundedEvent($this->context, 'refund_123', 'provider_order_456', 100.50, 'EUR', 'order_789');

        $reflection = new \ReflectionClass($event);
        $this->assertTrue($reflection->getProperty('context')->isReadOnly());
        $this->assertTrue($reflection->getProperty('refundId')->isReadOnly());
        $this->assertTrue($reflection->getProperty('providerOrderId')->isReadOnly());
        $this->assertTrue($reflection->getProperty('amount')->isReadOnly());
        $this->assertTrue($reflection->getProperty('currency')->isReadOnly());
        $this->assertTrue($reflection->getProperty('orderId')->isReadOnly());
    }
}
