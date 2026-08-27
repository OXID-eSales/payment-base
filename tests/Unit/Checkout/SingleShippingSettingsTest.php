<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout;

use OxidEsales\PaymentBase\Checkout\Contract\SingleShippingSettingsInterface;
use OxidEsales\PaymentBase\Checkout\SingleShippingSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Sprint 07 — the merchant's kill switch
 * (blPaymentBaseAutoAssignSingleShipping).
 *
 * Deliberately a second flag rather than a widening of sprint 06's: a merchant
 * may want one shortcut and not the other, and folding them together would
 * silently change behaviour that is already verified live.
 */
#[CoversClass(SingleShippingSettings::class)]
final class SingleShippingSettingsTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(SingleShippingSettingsInterface::class, new SingleShippingSettings());
    }

    public function testEnabledWhenFlagIsTrue(): void
    {
        $settings = new class () extends SingleShippingSettings {
            protected function readFlag(): bool
            {
                return true;
            }
        };

        $this->assertTrue($settings->isAutoAssignEnabled());
    }

    public function testDisabledWhenFlagIsFalse(): void
    {
        $settings = new class () extends SingleShippingSettings {
            protected function readFlag(): bool
            {
                return false;
            }
        };

        $this->assertFalse($settings->isAutoAssignEnabled());
    }

    /**
     * A broken setting store must fall back to the behaviour the shop had
     * before this feature existed: show the delivery-set selector.
     */
    public function testUnreadableSettingFallsBackToTheUnchangedCheckout(): void
    {
        $settings = new class () extends SingleShippingSettings {
            protected function readFlag(): bool
            {
                throw new RuntimeException('setting store unavailable');
            }
        };

        $this->assertFalse($settings->isAutoAssignEnabled());
    }
}
