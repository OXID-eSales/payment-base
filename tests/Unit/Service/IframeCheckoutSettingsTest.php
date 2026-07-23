<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Service;

use OxidEsales\PaymentBase\Service\IframeCheckoutSettings;
use OxidEsales\PaymentBase\Service\IframeCheckoutSettingsInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * IFRAME-01 — the provider-agnostic read accessor for the
 * "Use iframe instead of checkout button" flag (blPaymentBaseUseIframe).
 *
 * The flag is read through OXID's ModuleSettingService (the same store the
 * admin module-config form writes) behind a protected readFlag() seam, so the
 * pure decision logic is unit-testable without the shop bootstrap — matching
 * the payment-base "testable without shop bootstrap" convention.
 */
final class IframeCheckoutSettingsTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(
            IframeCheckoutSettingsInterface::class,
            new IframeCheckoutSettings()
        );
    }

    public function testEnabledWhenFlagIsTrue(): void
    {
        $service = new class () extends IframeCheckoutSettings {
            protected function readFlag(): bool
            {
                return true;
            }
        };

        $this->assertTrue($service->isEnabled());
    }

    public function testDisabledWhenFlagIsFalse(): void
    {
        $service = new class () extends IframeCheckoutSettings {
            protected function readFlag(): bool
            {
                return false;
            }
        };

        $this->assertFalse($service->isEnabled());
    }

    /**
     * The flag governs an optional presentation mode; a broken or unset setting
     * store must never take checkout down. Default to the safe path (redirect).
     */
    public function testDefaultsToFalseWhenReadThrows(): void
    {
        $service = new class () extends IframeCheckoutSettings {
            protected function readFlag(): bool
            {
                throw new RuntimeException('setting store unavailable');
            }
        };

        $this->assertFalse($service->isEnabled());
    }
}
