<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Tests\Unit\EventSystem\Event\Payment;

use PHPUnit\Framework\TestCase;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\PaymentInitiatedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\PaymentInitiatedEventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\PaymentEventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;

final class PaymentInitiatedEventTest extends TestCase
{
    private EventContext $context;

    protected function setUp(): void
    {
        $this->context = new EventContext(['userId' => 'user_456']);
    }

    public function testImplementsPaymentInitiatedEventInterface(): void
    {
        $event = new PaymentInitiatedEvent(
            $this->context,
            'stripe',
            100.50,
            'EUR',
            'https://shop.com/return',
            'https://shop.com/cancel'
        );

        $this->assertInstanceOf(PaymentInitiatedEventInterface::class, $event);
    }

    public function testImplementsPaymentEventInterface(): void
    {
        $event = new PaymentInitiatedEvent(
            $this->context,
            'stripe',
            100.50,
            'EUR',
            'https://shop.com/return',
            'https://shop.com/cancel'
        );

        $this->assertInstanceOf(PaymentEventInterface::class, $event);
    }

    public function testImplementsEventInterface(): void
    {
        $event = new PaymentInitiatedEvent(
            $this->context,
            'stripe',
            100.50,
            'EUR',
            'https://shop.com/return',
            'https://shop.com/cancel'
        );

        $this->assertInstanceOf(EventInterface::class, $event);
    }

    public function testGetContext_ReturnsContext(): void
    {
        $event = new PaymentInitiatedEvent(
            $this->context,
            'stripe',
            100.50,
            'EUR',
            'https://shop.com/return',
            'https://shop.com/cancel'
        );

        $this->assertSame($this->context, $event->getContext());
    }

    public function testGetPaymentMethodId_ReturnsPaymentMethodId(): void
    {
        $event = new PaymentInitiatedEvent(
            $this->context,
            'paypal',
            100.50,
            'EUR',
            'https://shop.com/return',
            'https://shop.com/cancel'
        );

        $this->assertEquals('paypal', $event->getPaymentMethodId());
    }

    public function testGetAmount_ReturnsAmount(): void
    {
        $event = new PaymentInitiatedEvent(
            $this->context,
            'stripe',
            250.75,
            'EUR',
            'https://shop.com/return',
            'https://shop.com/cancel'
        );

        $this->assertEquals(250.75, $event->getAmount());
    }

    public function testGetCurrency_ReturnsCurrency(): void
    {
        $event = new PaymentInitiatedEvent(
            $this->context,
            'stripe',
            100.50,
            'USD',
            'https://shop.com/return',
            'https://shop.com/cancel'
        );

        $this->assertEquals('USD', $event->getCurrency());
    }

    public function testGetReturnUrl_ReturnsReturnUrl(): void
    {
        $event = new PaymentInitiatedEvent(
            $this->context,
            'stripe',
            100.50,
            'EUR',
            'https://shop.com/payment/success',
            'https://shop.com/cancel'
        );

        $this->assertEquals('https://shop.com/payment/success', $event->getReturnUrl());
    }

    public function testGetCancelUrl_ReturnsCancelUrl(): void
    {
        $event = new PaymentInitiatedEvent(
            $this->context,
            'stripe',
            100.50,
            'EUR',
            'https://shop.com/return',
            'https://shop.com/payment/cancelled'
        );

        $this->assertEquals('https://shop.com/payment/cancelled', $event->getCancelUrl());
    }

    public function testSetProviderRedirectUrl_StoresUrl(): void
    {
        $event = new PaymentInitiatedEvent(
            $this->context,
            'stripe',
            100.50,
            'EUR',
            'https://shop.com/return',
            'https://shop.com/cancel'
        );

        $event->setProviderRedirectUrl('https://payment-provider.com/redirect/abc123');

        $this->assertEquals('https://payment-provider.com/redirect/abc123', $event->getProviderRedirectUrl());
    }

    public function testGetProviderRedirectUrl_WhenNotSet_ReturnsNull(): void
    {
        $event = new PaymentInitiatedEvent(
            $this->context,
            'stripe',
            100.50,
            'EUR',
            'https://shop.com/return',
            'https://shop.com/cancel'
        );

        $this->assertNull($event->getProviderRedirectUrl());
    }

    public function testSetProviderOrderId_StoresOrderId(): void
    {
        $event = new PaymentInitiatedEvent(
            $this->context,
            'stripe',
            100.50,
            'EUR',
            'https://shop.com/return',
            'https://shop.com/cancel'
        );

        $event->setProviderOrderId('provider_order_xyz789');

        $this->assertEquals('provider_order_xyz789', $event->getProviderOrderId());
    }

    public function testGetProviderOrderId_WhenNotSet_ReturnsNull(): void
    {
        $event = new PaymentInitiatedEvent(
            $this->context,
            'stripe',
            100.50,
            'EUR',
            'https://shop.com/return',
            'https://shop.com/cancel'
        );

        $this->assertNull($event->getProviderOrderId());
    }

    public function testConstructor_UsesReadonlyPropertiesForImmutableData(): void
    {
        $event = new PaymentInitiatedEvent(
            $this->context,
            'stripe',
            100.50,
            'EUR',
            'https://shop.com/return',
            'https://shop.com/cancel'
        );

        $reflection = new \ReflectionClass($event);
        $this->assertTrue($reflection->getProperty('context')->isReadOnly());
        $this->assertTrue($reflection->getProperty('paymentMethodId')->isReadOnly());
        $this->assertTrue($reflection->getProperty('amount')->isReadOnly());
        $this->assertTrue($reflection->getProperty('currency')->isReadOnly());
        $this->assertTrue($reflection->getProperty('returnUrl')->isReadOnly());
        $this->assertTrue($reflection->getProperty('cancelUrl')->isReadOnly());
    }
}
