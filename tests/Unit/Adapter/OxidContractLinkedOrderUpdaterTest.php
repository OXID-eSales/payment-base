<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Adapter;

use OxidEsales\PaymentBase\Adapter\ContractLinkedOrderUpdaterInterface;
use OxidEsales\PaymentBase\Adapter\LinkedOrderEndingInterface;
use OxidEsales\PaymentBase\Adapter\OxidContractLinkedOrderUpdater;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Union of the Stripe and Mollie tests. The ending itself is tested with OxidLinkedOrderEnding.
 */
#[CoversClass(OxidContractLinkedOrderUpdater::class)]
final class OxidContractLinkedOrderUpdaterTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        self::assertInstanceOf(
            ContractLinkedOrderUpdaterInterface::class,
            new OxidContractLinkedOrderUpdater($this->createMock(LinkedOrderEndingInterface::class))
        );
    }

    public function testMarkCancelledEndsTheOrderAsCancelled(): void
    {
        $ending = $this->createMock(LinkedOrderEndingInterface::class);
        $ending->expects(self::once())->method('end')->with('order-123', 'CANCELLED');

        (new OxidContractLinkedOrderUpdater($ending))->markCancelled('order-123');
    }

    public function testMarkFailedEndsTheOrderAsFailedWhateverTheReason(): void
    {
        $ending = $this->createMock(LinkedOrderEndingInterface::class);
        $ending->expects(self::exactly(2))->method('end')->with(self::anything(), 'FAILED');

        $updater = new OxidContractLinkedOrderUpdater($ending);
        $updater->markFailed('order-456', 'card_declined');
        $updater->markFailed('order-457', 'payment_declined');
    }
}
