<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout\Headless;

use OxidEsales\PaymentBase\Checkout\Headless\HeadlessTokenHash;
use PHPUnit\Framework\TestCase;

final class HeadlessTokenHashTest extends TestCase
{
    public function testFromToken_AnyToken_StoresSha256HexNotThePlaintext(): void
    {
        $hash = HeadlessTokenHash::fromToken('tok-fixed');

        self::assertSame(hash('sha256', 'tok-fixed'), $hash->value);
        self::assertStringNotContainsString('tok-fixed', $hash->value);
    }

    public function testMatches_TheTokenItWasBuiltFrom_IsTrue(): void
    {
        self::assertTrue(HeadlessTokenHash::fromToken('tok-fixed')->matches('tok-fixed'));
    }

    public function testMatches_AnotherToken_IsFalse(): void
    {
        self::assertFalse(HeadlessTokenHash::fromToken('tok-fixed')->matches('other'));
    }

    public function testMatches_TheStoredHashPresentedAsToken_IsFalse(): void
    {
        $hash = HeadlessTokenHash::fromToken('tok-fixed');

        self::assertFalse($hash->matches($hash->value), 'a database read must not be enough to authorise');
    }

    public function testFromStored_AValueReadFromTheDatabase_MatchesTheOriginalToken(): void
    {
        $stored = HeadlessTokenHash::fromToken('tok-fixed')->value;

        self::assertTrue(HeadlessTokenHash::fromStored($stored)->matches('tok-fixed'));
    }

    public function testFromStored_APlaintextToken_NeverMatchesIt(): void
    {
        self::assertFalse(HeadlessTokenHash::fromStored('tok-fixed')->matches('tok-fixed'));
    }
}
