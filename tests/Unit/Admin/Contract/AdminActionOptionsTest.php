<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Admin\Contract;

use OxidEsales\PaymentBase\Admin\Contract\AdminActionOptions;
use PHPUnit\Framework\TestCase;

final class AdminActionOptionsTest extends TestCase
{
    public function testFreeText_IsTodaysBehaviour(): void
    {
        $options = AdminActionOptions::freeText();

        self::assertSame([], $options->refundReasons);
        self::assertSame([], $options->voidReasons);
        self::assertFalse($options->refundDescription);
        self::assertSame(255, $options->reasonMaxLength);
    }

    public function testReasonMaxLength_IsKeptWithinWhatTheApiAccepts(): void
    {
        self::assertSame(255, (new AdminActionOptions([], [], false, 1000))->reasonMaxLength);
        self::assertSame(1, (new AdminActionOptions([], [], false, 0))->reasonMaxLength);
        self::assertSame(40, (new AdminActionOptions([], [], false, 40))->reasonMaxLength);
    }

    public function testReasons_AreListsOfNonEmptyDistinctStrings(): void
    {
        $options = new AdminActionOptions(['duplicate', '', 'duplicate', 'fraudulent'], ['x'], true, 255);

        self::assertSame(['duplicate', 'fraudulent'], $options->refundReasons);
        self::assertSame(['x'], $options->voidReasons);
        self::assertTrue($options->refundDescription);
    }
}
