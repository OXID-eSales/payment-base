<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\EventSystem\Event\Request;

use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\Event\EventInterface;
use OxidEsales\PaymentBase\EventSystem\Event\Request\AbstractProviderRequestEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Request\CancelAuthorizationRequestedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Request\CaptureRequestedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Request\RefundRequestedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\Request\VoidAuthorizationRequestedEvent;
use PHPUnit\Framework\TestCase;

final class AbstractProviderRequestEventTest extends TestCase
{
    public function testRefundRequestCarriesContextAmountAndReason(): void
    {
        $context = new EventContext(['providerName' => 'stripe']);
        $event = new RefundRequestedEvent($context, 12.5, 'customer_request');

        self::assertInstanceOf(EventInterface::class, $event);
        self::assertInstanceOf(AbstractProviderRequestEvent::class, $event);
        self::assertSame($context, $event->getContext());
        self::assertSame(12.5, $event->getAmount());
        self::assertSame('customer_request', $event->getReason());
    }

    public function testCaptureRequestSupportsNullAmountMeaningFull(): void
    {
        $event = new CaptureRequestedEvent(new EventContext());
        self::assertNull($event->getAmount());
        self::assertNull($event->getReason());
    }

    public function testCancelAuthorizationIsConcreteSubclass(): void
    {
        $event = new CancelAuthorizationRequestedEvent(new EventContext());
        self::assertInstanceOf(AbstractProviderRequestEvent::class, $event);
    }

    public function testVoidAuthorizationIsConcreteSubclass(): void
    {
        $event = new VoidAuthorizationRequestedEvent(new EventContext());
        self::assertInstanceOf(AbstractProviderRequestEvent::class, $event);
    }
}
