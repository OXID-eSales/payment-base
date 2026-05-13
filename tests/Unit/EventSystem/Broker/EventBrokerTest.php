<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\EventSystem\Broker;

use OxidEsales\PaymentBase\EventSystem\Broker\EventBroker;
use OxidEsales\PaymentBase\EventSystem\Broker\ProviderEventTranslatorInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\Event\EventInterface;
use OxidEsales\PaymentBase\EventSystem\Event\Request\AbstractProviderRequestEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Request\RefundRequestedEvent;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use PHPUnit\Framework\TestCase;

final class EventBrokerTest extends TestCase
{
    public function testDispatchRoutesToMatchingTranslator(): void
    {
        $translated = $this->dummyEvent('stripe.refund');
        $translator = $this->fakeTranslator('stripe', $translated);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->with($translated);

        $broker = new EventBroker($dispatcher, [$translator]);
        $context = new EventContext(['providerName' => 'stripe']);
        $broker->dispatch(new RefundRequestedEvent($context, 10.0, 'x'));
    }

    public function testDispatchNoOpWhenNoTranslatorAndNoConventionClass(): void
    {
        // STRP-AUTOCAP-REFUND: when there is no explicit translator AND the
        // convention class does not exist (third-party provider not installed),
        // the broker is a loud no-op (error-logs, returns event unchanged).
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $broker = new EventBroker($dispatcher, []);
        $context = new EventContext(['providerName' => 'nonexistent_provider_xyz']);
        $broker->dispatch(new RefundRequestedEvent($context, 1.0));
    }

    public function testConventionPathDispatchesWhenNoTranslatorButClassExists(): void
    {
        // STRP-AUTOCAP-REFUND: a provider that does NOT register a translator
        // but ships an event class following the naming convention still gets
        // its event dispatched — payment-module-agnostic by construction.
        $dispatched = null;
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(function (EventInterface $e) use (&$dispatched) {
                $dispatched = $e;
                return $e;
            });

        $broker = new EventBroker($dispatcher, []);
        $context = new EventContext(['providerName' => 'stripe']);
        $broker->dispatch(new RefundRequestedEvent($context, 1.0));

        self::assertInstanceOf(
            \OxidEsales\Payments\Stripe\EventSystem\Event\StripeRefundRequestEvent::class,
            $dispatched,
            'convention-based resolution must construct StripeRefundRequestEvent for stripe provider'
        );
    }

    public function testDispatchNoOpWhenTranslatorReturnsNull(): void
    {
        $translator = $this->fakeTranslator('stripe', null);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $broker = new EventBroker($dispatcher, [$translator]);
        $broker->dispatch(new RefundRequestedEvent(new EventContext(['providerName' => 'stripe'])));
    }

    public function testProviderNameContextKeyWinsOverContract(): void
    {
        $translator = $this->fakeTranslator('stripe', $this->dummyEvent('stripe.refund'));
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch');

        $broker = new EventBroker($dispatcher, [$translator]);
        $broker->dispatch(new RefundRequestedEvent(new EventContext(['providerName' => 'stripe'])));
    }

    public function testPicksOnlyTranslatorThatSupportsProvider(): void
    {
        $payPalTranslator = $this->fakeTranslator('paypal', $this->dummyEvent('paypal.refund'));
        $stripeTranslator = $this->fakeTranslator('stripe', $this->dummyEvent('stripe.refund'));

        $dispatched = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(function (EventInterface $e) use (&$dispatched) {
            $dispatched[] = $e;
            return $e;
        });

        $broker = new EventBroker($dispatcher, [$payPalTranslator, $stripeTranslator]);
        $broker->dispatch(new RefundRequestedEvent(new EventContext(['providerName' => 'stripe'])));

        self::assertCount(1, $dispatched);
        self::assertSame('stripe.refund', $dispatched[0]->tag);
    }

    public function testReturnsOriginalEventAsDispatchResult(): void
    {
        $broker = new EventBroker($this->createMock(EventDispatcherInterface::class), []);
        $original = new RefundRequestedEvent(new EventContext(['providerName' => 'x']));
        $result = $broker->dispatch($original);
        self::assertSame($original, $result);
    }

    private function fakeTranslator(string $supports, ?EventInterface $translate): ProviderEventTranslatorInterface
    {
        return new class ($supports, $translate) implements ProviderEventTranslatorInterface {
            public function __construct(
                private readonly string $supports,
                private readonly ?EventInterface $translate,
            ) {
            }
            public function supports(string $providerName): bool
            {
                return $providerName === $this->supports;
            }
            public function translate(AbstractProviderRequestEvent $event): ?EventInterface
            {
                return $this->translate;
            }
        };
    }

    private function dummyEvent(string $tag): EventInterface
    {
        return new class ($tag) implements EventInterface {
            public function __construct(public readonly string $tag)
            {
            }
        };
    }
}
