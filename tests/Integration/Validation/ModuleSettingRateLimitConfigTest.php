<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Validation;

use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Bridge\ModuleSettingBridgeInterface;
use OxidEsales\PaymentBase\Validation\RateLimit\ModuleSettingRateLimitConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * Sprint 29 (VRL): the limit is the admin setting, not a literal 30. In the shop-context suite: the CE settings bridge
 * is not part of the standalone unit suite's autoloader.
 */
#[Group('integration')]
#[CoversClass(ModuleSettingRateLimitConfig::class)]
final class ModuleSettingRateLimitConfigTest extends TestCase
{
    public function testReadsTheSetting_AsSavedByTheAdmin(): void
    {
        self::assertSame(5, $this->config('5')->getLimitPerMinute(), 'OXID stores a saved num setting as a string');
        self::assertSame(30, $this->config(30)->getLimitPerMinute());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function misconfigured(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => ['-3'];
        yield 'empty' => [''];
        yield 'text' => ['many'];
        yield 'missing' => [null];
    }

    #[DataProvider('misconfigured')]
    public function testAValueThatIsNotAPositiveNumber_FailsInsteadOfGuessing(mixed $raw): void
    {
        $this->expectException(UnexpectedValueException::class);

        $this->config($raw)->getLimitPerMinute();
    }

    private function config(mixed $raw): ModuleSettingRateLimitConfig
    {
        $bridge = $this->createStub(ModuleSettingBridgeInterface::class);
        $bridge->method('get')->willReturn($raw);

        return new ModuleSettingRateLimitConfig($bridge);
    }
}
