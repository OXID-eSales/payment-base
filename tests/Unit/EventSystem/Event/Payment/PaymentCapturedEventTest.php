<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\EventSystem\Event\Payment;

use PHPUnit\Framework\TestCase;
use OxidEsales\PaymentBase\EventSystem\Event\Payment\PaymentCapturedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Payment\PaymentCapturedEventInterface;
use OxidEsales\PaymentBase\EventSystem\Event\Payment\PaymentEventInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;

final class PaymentCapturedEventTest extends TestCase
{
    private EventContext $context;

    protected function setUp(): void
    {
        $this->context = new EventContext(['userId' => 'user_456']);
    }

    public function testImplementsPaymentCapturedEventInterface(): void
    {
        $event = new PaymentCapturedEvent($this->context, 'auth_123', 'capture_456', 100.50, 'EUR');

        $this->assertInstanceOf(PaymentCapturedEventInterface::class, $event);
    }

    public function testImplementsPaymentEventInterface(): void
    {
        $event = new PaymentCapturedEvent($this->context, 'auth_123', 'capture_456', 100.50, 'EUR');

        $this->assertInstanceOf(PaymentEventInterface::class, $event);
    }

    public function testImplementsEventInterface(): void
    {
        $event = new PaymentCapturedEvent($this->context, 'auth_123', 'capture_456', 100.50, 'EUR');

        $this->assertInstanceOf(EventInterface::class, $event);
    }

    public function testGetContext_ReturnsContext(): void
    {
        $event = new PaymentCapturedEvent($this->context, 'auth_123', 'capture_456', 100.50, 'EUR');

        $this->assertSame($this->context, $event->getContext());
    }

    public function testGetAuthorizationId_ReturnsAuthorizationId(): void
    {
        $event = new PaymentCapturedEvent($this->context, 'auth_xyz789', 'capture_456', 100.50, 'EUR');

        $this->assertEquals('auth_xyz789', $event->getAuthorizationId());
    }

    public function testGetCaptureId_ReturnsCaptureId(): void
    {
        $event = new PaymentCapturedEvent($this->context, 'auth_123', 'capture_abc123', 100.50, 'EUR');

        $this->assertEquals('capture_abc123', $event->getCaptureId());
    }

    public function testGetCapturedAmount_ReturnsCapturedAmount(): void
    {
        $event = new PaymentCapturedEvent($this->context, 'auth_123', 'capture_456', 250.75, 'EUR');

        $this->assertEquals(250.75, $event->getCapturedAmount());
    }

    public function testGetCurrency_ReturnsCurrency(): void
    {
        $event = new PaymentCapturedEvent($this->context, 'auth_123', 'capture_456', 100.50, 'USD');

        $this->assertEquals('USD', $event->getCurrency());
    }

    public function testEvent_IsImmutable(): void
    {
        $event = new PaymentCapturedEvent($this->context, 'auth_123', 'capture_456', 100.50, 'EUR');

        $this->assertFalse(method_exists($event, 'setContext'));
        $this->assertFalse(method_exists($event, 'setAuthorizationId'));
        $this->assertFalse(method_exists($event, 'setCaptureId'));
    }

    public function testConstructor_UsesReadonlyProperties(): void
    {
        $event = new PaymentCapturedEvent($this->context, 'auth_123', 'capture_456', 100.50, 'EUR');

        $reflection = new \ReflectionClass($event);
        $this->assertTrue($reflection->getProperty('context')->isReadOnly());
        $this->assertTrue($reflection->getProperty('authorizationId')->isReadOnly());
        $this->assertTrue($reflection->getProperty('captureId')->isReadOnly());
        $this->assertTrue($reflection->getProperty('capturedAmount')->isReadOnly());
        $this->assertTrue($reflection->getProperty('currency')->isReadOnly());
    }
}
