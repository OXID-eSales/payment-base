<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\EventSystem\Event\Payment;

use PHPUnit\Framework\TestCase;
use OxidEsales\PaymentBase\EventSystem\Event\Payment\PaymentFailedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Payment\PaymentFailedEventInterface;
use OxidEsales\PaymentBase\EventSystem\Event\Payment\PaymentEventInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;

final class PaymentFailedEventTest extends TestCase
{
    private EventContext $context;

    protected function setUp(): void
    {
        $this->context = new EventContext(['userId' => 'user_456']);
    }

    public function testImplementsPaymentFailedEventInterface(): void
    {
        $event = new PaymentFailedEvent($this->context, 'provider_order_123', 'INSUFFICIENT_FUNDS', 'Insufficient funds');

        $this->assertInstanceOf(PaymentFailedEventInterface::class, $event);
    }

    public function testImplementsPaymentEventInterface(): void
    {
        $event = new PaymentFailedEvent($this->context, 'provider_order_123', 'INSUFFICIENT_FUNDS', 'Insufficient funds');

        $this->assertInstanceOf(PaymentEventInterface::class, $event);
    }

    public function testImplementsEventInterface(): void
    {
        $event = new PaymentFailedEvent($this->context, 'provider_order_123', 'INSUFFICIENT_FUNDS', 'Insufficient funds');

        $this->assertInstanceOf(EventInterface::class, $event);
    }

    public function testGetContext_ReturnsContext(): void
    {
        $event = new PaymentFailedEvent($this->context, 'provider_order_123', 'INSUFFICIENT_FUNDS', 'Insufficient funds');

        $this->assertSame($this->context, $event->getContext());
    }

    public function testGetProviderOrderId_ReturnsProviderOrderId(): void
    {
        $event = new PaymentFailedEvent($this->context, 'stripe_order_xyz', 'CARD_DECLINED', 'Card declined');

        $this->assertEquals('stripe_order_xyz', $event->getProviderOrderId());
    }

    public function testGetErrorCode_ReturnsErrorCode(): void
    {
        $event = new PaymentFailedEvent($this->context, 'provider_order_123', 'PAYMENT_TIMEOUT', 'Payment timeout');

        $this->assertEquals('PAYMENT_TIMEOUT', $event->getErrorCode());
    }

    public function testGetErrorMessage_ReturnsErrorMessage(): void
    {
        $event = new PaymentFailedEvent($this->context, 'provider_order_123', 'CARD_DECLINED', 'Card declined by issuer');

        $this->assertEquals('Card declined by issuer', $event->getErrorMessage());
    }

    public function testEvent_IsImmutable(): void
    {
        $event = new PaymentFailedEvent($this->context, 'provider_order_123', 'INSUFFICIENT_FUNDS', 'Insufficient funds');

        $this->assertFalse(method_exists($event, 'setContext'));
        $this->assertFalse(method_exists($event, 'setProviderOrderId'));
        $this->assertFalse(method_exists($event, 'setErrorCode'));
    }

    public function testConstructor_UsesReadonlyProperties(): void
    {
        $event = new PaymentFailedEvent($this->context, 'provider_order_123', 'INSUFFICIENT_FUNDS', 'Insufficient funds');

        $reflection = new \ReflectionClass($event);
        $this->assertTrue($reflection->getProperty('context')->isReadOnly());
        $this->assertTrue($reflection->getProperty('providerOrderId')->isReadOnly());
        $this->assertTrue($reflection->getProperty('errorCode')->isReadOnly());
        $this->assertTrue($reflection->getProperty('errorMessage')->isReadOnly());
    }
}
