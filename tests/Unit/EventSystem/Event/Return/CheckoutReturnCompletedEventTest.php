<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\EventSystem\Event\Return;

use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\Event\EventInterface;
use OxidEsales\PaymentBase\EventSystem\Event\Return\CheckoutReturnCompletedEvent;
use OxidEsales\PaymentBase\Return\ReturnResolution;
use PHPUnit\Framework\TestCase;

final class CheckoutReturnCompletedEventTest extends TestCase
{
    public function testImplementsEventInterface(): void
    {
        $event = new CheckoutReturnCompletedEvent(
            new EventContext(),
            ReturnResolution::authorized('auth_1', 'ord_1', 1.0, 'EUR'),
        );
        self::assertInstanceOf(EventInterface::class, $event);
    }

    public function testExposesContextAndResolution(): void
    {
        $context = new EventContext(['providerName' => 'stripe']);
        $resolution = ReturnResolution::readyToCommit('auth_1', 'ord_1', 10.0, 'EUR');

        $event = new CheckoutReturnCompletedEvent($context, $resolution);

        self::assertSame($context, $event->getContext());
        self::assertSame($resolution, $event->getResolution());
    }
}
