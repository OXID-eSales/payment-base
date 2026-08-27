<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;

/**
 * Sprint 07 — the shipping kill switch as metadata.php declares it.
 *
 * The declaration is what OXID's `oe:module:install` reads; getting the type or
 * the default wrong is invisible until a merchant opens the admin form, and a
 * default of `false` would ship the feature switched off.
 *
 * SettingsTranslationsTest already guards that the setting has admin labels in
 * every language; this guards the declaration itself.
 */
final class SingleShippingSettingTest extends TestCase
{
    private const SETTING = 'blPaymentBaseAutoAssignSingleShipping';
    private const PAYMENT_SETTING = 'blPaymentBaseAutoAssignSinglePayment';

    /** @return array<int, array{name: string, type?: string, value?: mixed, group?: string}> */
    private function settings(): array
    {
        $aModule = [];
        require dirname(__DIR__, 3) . '/metadata.php';

        return $aModule['settings'] ?? [];
    }

    /** @return array{name: string, type?: string, value?: mixed, group?: string} */
    private function setting(string $name): array
    {
        foreach ($this->settings() as $setting) {
            if ($setting['name'] === $name) {
                return $setting;
            }
        }

        $this->fail("metadata.php declares no setting named {$name}");
    }

    public function testTheShippingKillSwitchIsDeclared(): void
    {
        $this->assertSame('bool', $this->setting(self::SETTING)['type'] ?? null);
    }

    /**
     * Default on — it can only ever fire when there is nothing to choose.
     */
    public function testItShipsEnabled(): void
    {
        $this->assertTrue($this->setting(self::SETTING)['value'] ?? null);
    }

    /**
     * Reuses sprint 06's group, so only the field key is new in the admin form.
     */
    public function testItSharesTheCheckoutFlowGroupWithItsPaymentSibling(): void
    {
        $this->assertSame('checkout_flow', $this->setting(self::SETTING)['group'] ?? null);
        $this->assertSame('checkout_flow', $this->setting(self::PAYMENT_SETTING)['group'] ?? null);
    }

    /**
     * Two switches, not one. Folding them together would change sprint 06's
     * verified behaviour for any merchant who wants only one of the shortcuts.
     */
    public function testTheTwoShortcutsHaveSeparateSwitches(): void
    {
        $this->assertNotSame(self::SETTING, self::PAYMENT_SETTING);
        $this->assertSame(self::PAYMENT_SETTING, $this->setting(self::PAYMENT_SETTING)['name']);
    }
}
