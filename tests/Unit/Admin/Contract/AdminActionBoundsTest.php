<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Admin\Contract;

use OxidEsales\PaymentBase\Admin\Contract\AdminActionBounds;
use PHPUnit\Framework\TestCase;

final class AdminActionBoundsTest extends TestCase
{
    public function testBounds_AreNeverNegativeOrNotANumber(): void
    {
        $bounds = new AdminActionBounds(-5.0, NAN);

        self::assertSame(0.0, $bounds->capturable);
        self::assertSame(0.0, $bounds->refundable, 'an unusable number is the closed one');
    }

    public function testOmittedBound_MeansThePaymentBaseNumberStands(): void
    {
        $bounds = new AdminActionBounds(capturable: 12.5);

        self::assertSame(12.5, $bounds->capturable);
        self::assertNull($bounds->refundable);
        self::assertTrue($bounds->available);
    }

    public function testUnavailable_IsClosedOnBothSides(): void
    {
        $bounds = AdminActionBounds::unavailable();

        self::assertFalse($bounds->available);
        self::assertSame([0.0, 0.0], [$bounds->capturable, $bounds->refundable]);
    }
}
