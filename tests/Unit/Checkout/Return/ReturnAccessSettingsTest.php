<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout\Return;

use OxidEsales\PaymentBase\Checkout\Return\ReturnAccessSettings;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * VR-20 R3 - the two knobs of the return leg: how long a return token lives, and the one-release
 * switch that still accepts the providers' pre-deploy tokens.
 */
final class ReturnAccessSettingsTest extends TestCase
{
    public function testTheTtlIsConfiguredInHours(): void
    {
        self::assertSame(2 * 3600, $this->reading(2, true)->tokenTtlSeconds());
    }

    public function testATtlBelowOneHourFallsBackToTheDefaultOfTwentyFourHours(): void
    {
        self::assertSame(24 * 3600, $this->reading(0, true)->tokenTtlSeconds());
    }

    public function testAnUnreadableStoreGivesTheDefaultTtlAndNoLegacyTokens(): void
    {
        $settings = new class () extends ReturnAccessSettings {
            protected function readHours(): int
            {
                throw new RuntimeException('store down');
            }

            protected function readLegacySwitch(): bool
            {
                throw new RuntimeException('store down');
            }
        };

        self::assertSame(24 * 3600, $settings->tokenTtlSeconds());
        self::assertFalse($settings->acceptsLegacyTokens());
    }

    public function testTheLegacySwitchIsReadFromTheSetting(): void
    {
        self::assertTrue($this->reading(24, true)->acceptsLegacyTokens());
        self::assertFalse($this->reading(24, false)->acceptsLegacyTokens());
    }

    private function reading(int $hours, bool $legacy): ReturnAccessSettings
    {
        return new class ($hours, $legacy) extends ReturnAccessSettings {
            public function __construct(private readonly int $hours, private readonly bool $legacy)
            {
            }

            protected function readHours(): int
            {
                return $this->hours;
            }

            protected function readLegacySwitch(): bool
            {
                return $this->legacy;
            }
        };
    }
}
