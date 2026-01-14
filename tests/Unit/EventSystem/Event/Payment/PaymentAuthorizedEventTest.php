<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\EventSystem\Event\Payment;

use PHPUnit\Framework\TestCase;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\PaymentAuthorizedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\PaymentAuthorizedEventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\PaymentEventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;

final class PaymentAuthorizedEventTest extends TestCase
{
    private EventContext $context;

    protected function setUp(): void
    {
        $this->context = new EventContext(['userId' => 'user_456']);
    }

    public function testImplementsPaymentAuthorizedEventInterface(): void
    {
        $event = new PaymentAuthorizedEvent($this->context, 'auth_123', 'provider_order_456', 100.50, 'EUR');

        $this->assertInstanceOf(PaymentAuthorizedEventInterface::class, $event);
    }

    public function testImplementsPaymentEventInterface(): void
    {
        $event = new PaymentAuthorizedEvent($this->context, 'auth_123', 'provider_order_456', 100.50, 'EUR');

        $this->assertInstanceOf(PaymentEventInterface::class, $event);
    }

    public function testImplementsEventInterface(): void
    {
        $event = new PaymentAuthorizedEvent($this->context, 'auth_123', 'provider_order_456', 100.50, 'EUR');

        $this->assertInstanceOf(EventInterface::class, $event);
    }

    public function testGetContext_ReturnsContext(): void
    {
        $event = new PaymentAuthorizedEvent($this->context, 'auth_123', 'provider_order_456', 100.50, 'EUR');

        $this->assertSame($this->context, $event->getContext());
    }

    public function testGetAuthorizationId_ReturnsAuthorizationId(): void
    {
        $event = new PaymentAuthorizedEvent($this->context, 'auth_xyz789', 'provider_order_456', 100.50, 'EUR');

        $this->assertEquals('auth_xyz789', $event->getAuthorizationId());
    }

    public function testGetProviderOrderId_ReturnsProviderOrderId(): void
    {
        $event = new PaymentAuthorizedEvent($this->context, 'auth_123', 'stripe_order_abc', 100.50, 'EUR');

        $this->assertEquals('stripe_order_abc', $event->getProviderOrderId());
    }

    public function testGetAmount_ReturnsAmount(): void
    {
        $event = new PaymentAuthorizedEvent($this->context, 'auth_123', 'provider_order_456', 250.75, 'EUR');

        $this->assertEquals(250.75, $event->getAmount());
    }

    public function testGetCurrency_ReturnsCurrency(): void
    {
        $event = new PaymentAuthorizedEvent($this->context, 'auth_123', 'provider_order_456', 100.50, 'USD');

        $this->assertEquals('USD', $event->getCurrency());
    }

    public function testEvent_IsImmutable(): void
    {
        $event = new PaymentAuthorizedEvent($this->context, 'auth_123', 'provider_order_456', 100.50, 'EUR');

        $this->assertFalse(method_exists($event, 'setContext'));
        $this->assertFalse(method_exists($event, 'setAuthorizationId'));
        $this->assertFalse(method_exists($event, 'setAmount'));
    }

    public function testConstructor_UsesReadonlyProperties(): void
    {
        $event = new PaymentAuthorizedEvent($this->context, 'auth_123', 'provider_order_456', 100.50, 'EUR');

        $reflection = new \ReflectionClass($event);
        $this->assertTrue($reflection->getProperty('context')->isReadOnly());
        $this->assertTrue($reflection->getProperty('authorizationId')->isReadOnly());
        $this->assertTrue($reflection->getProperty('providerOrderId')->isReadOnly());
        $this->assertTrue($reflection->getProperty('amount')->isReadOnly());
        $this->assertTrue($reflection->getProperty('currency')->isReadOnly());
    }
}
