<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Validation\User;

use OxidEsales\PaymentBase\Validation\User\OxidUserFieldReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OxidUserFieldReader::class)]
final class OxidUserFieldReaderTest extends TestCase
{
    public function testMapsLogicalNamesToOxidColumns(): void
    {
        self::assertSame('oxfname', OxidUserFieldReader::oxidColumn('firstName'));
        self::assertSame('oxmobfon', OxidUserFieldReader::oxidColumn('personalPhone'));
        self::assertSame('oxusername', OxidUserFieldReader::oxidColumn('email'));
    }

    public function testUnknownLogicalNameHasNoColumn(): void
    {
        self::assertNull(OxidUserFieldReader::oxidColumn('captureReason'));
    }
}
