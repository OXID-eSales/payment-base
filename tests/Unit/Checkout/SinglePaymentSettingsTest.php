<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Checkout;

use OxidEsales\PaymentBase\Checkout\Contract\SinglePaymentSettingsInterface;
use OxidEsales\PaymentBase\Checkout\SinglePaymentSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Sprint 06 — the merchant's kill switch
 * (blPaymentBaseAutoAssignSinglePayment).
 */
#[CoversClass(SinglePaymentSettings::class)]
final class SinglePaymentSettingsTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(SinglePaymentSettingsInterface::class, new SinglePaymentSettings());
    }

    public function testEnabledWhenFlagIsTrue(): void
    {
        $settings = new class () extends SinglePaymentSettings {
            protected function readFlag(): bool
            {
                return true;
            }
        };

        $this->assertTrue($settings->isAutoAssignEnabled());
    }

    public function testDisabledWhenFlagIsFalse(): void
    {
        $settings = new class () extends SinglePaymentSettings {
            protected function readFlag(): bool
            {
                return false;
            }
        };

        $this->assertFalse($settings->isAutoAssignEnabled());
    }

    /**
     * A broken setting store must fall back to the behaviour the shop had
     * before this feature existed: show the payment step.
     */
    public function testUnreadableSettingFallsBackToTheUnchangedCheckout(): void
    {
        $settings = new class () extends SinglePaymentSettings {
            protected function readFlag(): bool
            {
                throw new RuntimeException('setting store unavailable');
            }
        };

        $this->assertFalse($settings->isAutoAssignEnabled());
    }
}
