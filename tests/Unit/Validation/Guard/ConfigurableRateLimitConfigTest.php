<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Validation\Guard;

use OxidEsales\PaymentBase\Validation\RateLimit\ConfigurableRateLimitConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigurableRateLimitConfig::class)]
class ConfigurableRateLimitConfigTest extends TestCase
{
    public function testEveryPluginGetsTheGlobalDefault(): void
    {
        $config = new ConfigurableRateLimitConfig(30);

        $this->assertSame(30, $config->getLimitForPlugin('any_plugin'));
        $this->assertSame(30, $config->getLimitForPlugin('other_plugin'));
    }
}
