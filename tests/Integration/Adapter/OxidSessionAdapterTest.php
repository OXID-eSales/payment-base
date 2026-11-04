<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Adapter;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentBase\Adapter\OxidSessionAdapter;
use OxidEsales\PaymentBase\Adapter\SessionAdapterInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The one session adapter (VR-25 D0): union of the Stripe, Mollie and PayPal copy tests.
 */
#[CoversClass(OxidSessionAdapter::class)]
#[RunTestsInSeparateProcesses]
final class OxidSessionAdapterTest extends TestCase
{
    public function testImplementsTheSharedInterface(): void
    {
        $this->assertInstanceOf(SessionAdapterInterface::class, new OxidSessionAdapter());
    }

    public function testSetAndGetVariableRoundTrips(): void
    {
        $adapter = new OxidSessionAdapter();
        $adapter->setVariable('payment_base_test_var', 'hello');

        $this->assertSame('hello', $adapter->getVariable('payment_base_test_var'));
    }

    public function testTheBasketIsTheSessionsBasket(): void
    {
        $this->assertSame(Registry::getSession()->getBasket(), (new OxidSessionAdapter())->getBasket());
    }
}
