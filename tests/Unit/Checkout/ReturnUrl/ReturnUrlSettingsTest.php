<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout\ReturnUrl;

use OxidEsales\PaymentBase\Checkout\ReturnUrl\ReturnUrlSettings;
use OxidEsales\PaymentBase\Checkout\ReturnUrl\ReturnUrlSettingsInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Sprint 15 / S5 — `sPaymentBaseHeadlessReturnOrigins`: the storefront
 * origins a headless client may name as return targets, one text field,
 * separated by commas, spaces or newlines. Empty (the default) means the
 * shop itself only.
 */
final class ReturnUrlSettingsTest extends TestCase
{
    public function testImplementsTheInterface(): void
    {
        self::assertInstanceOf(ReturnUrlSettingsInterface::class, new ReturnUrlSettings());
    }

    public function testSplitsOnCommasSpacesAndNewlinesAndDropsBlanks(): void
    {
        $settings = $this->reading("https://app.example.com, https://m.example.com\n\n  app2.example.com ,");

        self::assertSame(
            ['https://app.example.com', 'https://m.example.com', 'app2.example.com'],
            $settings->getAllowedOrigins()
        );
    }

    public function testAnEmptySettingMeansNoExtraOrigins(): void
    {
        self::assertSame([], $this->reading('')->getAllowedOrigins());
        self::assertSame([], $this->reading("  \n ")->getAllowedOrigins());
    }

    /**
     * A broken setting store must fall back to the safe default: shop only.
     */
    public function testAnUnreadableSettingMeansNoExtraOrigins(): void
    {
        $settings = new class () extends ReturnUrlSettings {
            protected function readRaw(): string
            {
                throw new RuntimeException('no container');
            }
        };

        self::assertSame([], $settings->getAllowedOrigins());
    }

    private function reading(string $raw): ReturnUrlSettings
    {
        return new class ($raw) extends ReturnUrlSettings {
            public function __construct(private readonly string $raw)
            {
            }

            protected function readRaw(): string
            {
                return $this->raw;
            }
        };
    }
}
