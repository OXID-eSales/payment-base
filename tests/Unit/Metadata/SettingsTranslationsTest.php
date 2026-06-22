<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;

/**
 * Guards that every module setting declared in metadata.php has the admin
 * translation keys OXID's module-config form looks up — both the per-setting
 * label (SHOP_MODULE_<name>) and the group header (SHOP_MODULE_GROUP_<group>) —
 * in every admin language.
 *
 * Regression: settings added in STRP-129 (group `validation`) and STRP-157
 * (group `per_line_vat`) shipped without these keys, so the admin settings tab
 * logged "Translation for SHOP_MODULE_GROUP_validation not found!" etc.
 */
final class SettingsTranslationsTest extends TestCase
{
    private const ADMIN_LANGS = ['en', 'de'];

    /** @return array<int, array{name: string, group?: string}> */
    private function settings(): array
    {
        $aModule = [];
        require $this->moduleRoot() . '/metadata.php';

        return $aModule['settings'] ?? [];
    }

    /** @return array<string, string> */
    private function langKeys(string $lang): array
    {
        $aLang = [];
        require $this->moduleRoot() . "/views/admin_twig/{$lang}/payment_admin_lang.php";

        return $aLang;
    }

    private function moduleRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    public function testEverySettingGroupHasAdminTranslationInEveryLanguage(): void
    {
        $groups = array_values(array_unique(array_filter(
            array_map(static fn (array $s): string => $s['group'] ?? '', $this->settings())
        )));

        $this->assertNotEmpty($groups, 'Expected at least one grouped setting in metadata.php');

        foreach (self::ADMIN_LANGS as $lang) {
            $keys = $this->langKeys($lang);
            foreach ($groups as $group) {
                $key = 'SHOP_MODULE_GROUP_' . $group;
                $this->assertArrayHasKey($key, $keys, "Missing [$lang] $key");
                $this->assertNotSame('', trim($keys[$key]), "Empty [$lang] $key");
            }
        }
    }

    public function testEverySettingHasAdminLabelInEveryLanguage(): void
    {
        $names = array_map(static fn (array $s): string => $s['name'], $this->settings());

        $this->assertNotEmpty($names, 'Expected at least one setting in metadata.php');

        foreach (self::ADMIN_LANGS as $lang) {
            $keys = $this->langKeys($lang);
            foreach ($names as $name) {
                $key = 'SHOP_MODULE_' . $name;
                $this->assertArrayHasKey($key, $keys, "Missing [$lang] $key");
                $this->assertNotSame('', trim($keys[$key]), "Empty [$lang] $key");
            }
        }
    }
}
