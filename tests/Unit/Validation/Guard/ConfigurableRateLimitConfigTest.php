<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Validation\Guard;

use OxidEsales\PaymentBase\Validation\RateLimit\ConfigurableRateLimitConfig;
use OxidEsales\PaymentBase\Validation\RateLimit\RateLimitOverrideInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigurableRateLimitConfig::class)]
class ConfigurableRateLimitConfigTest extends TestCase
{
    public function testReturnsGlobalDefaultWhenNoOverrideMatches(): void
    {
        $config = new ConfigurableRateLimitConfig(30, []);

        $this->assertSame(30, $config->getLimitForPlugin('any_plugin'));
    }

    public function testReturnsOverrideLimitForMatchingPlugin(): void
    {
        $override = $this->makeOverride('acme_pay', 5);
        $config = new ConfigurableRateLimitConfig(30, [$override]);

        $this->assertSame(5, $config->getLimitForPlugin('acme_pay'));
    }

    public function testFirstMatchingOverrideWins(): void
    {
        $override1 = $this->makeOverride('acme_pay', 5);
        $override2 = $this->makeOverride('acme_pay', 10);
        $config = new ConfigurableRateLimitConfig(30, [$override1, $override2]);

        $this->assertSame(5, $config->getLimitForPlugin('acme_pay'));
    }

    public function testNonMatchingPluginFallsBackToDefault(): void
    {
        $override = $this->makeOverride('acme_pay', 5);
        $config = new ConfigurableRateLimitConfig(30, [$override]);

        $this->assertSame(30, $config->getLimitForPlugin('other_plugin'));
    }

    private function makeOverride(string $pluginId, int $limit): RateLimitOverrideInterface&MockObject
    {
        $override = $this->createMock(RateLimitOverrideInterface::class);
        $override->method('getPluginModuleId')->willReturn($pluginId);
        $override->method('getLimitPerMinute')->willReturn($limit);

        return $override;
    }
}
