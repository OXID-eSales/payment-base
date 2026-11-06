<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;

/**
 * Sprint 27 (RC) — the merchant has nothing to decide about VAT rounding.
 *
 * Per-line rounding is a provider-payload concern (invariant I2); the shop's
 * own totals are OXID's (invariant I1). A setting that let the merchant switch
 * the shop's rounding was removed; this test keeps it from coming back.
 */
final class MetadataSettingsTest extends TestCase
{
    /** @return array<int, array{name: string, type?: string, value?: mixed, group?: string}> */
    private function settings(): array
    {
        $aModule = [];
        require dirname(__DIR__, 3) . '/metadata.php';

        return $aModule['settings'] ?? [];
    }

    public function testSettings_PerLineVat_IsNotDeclared(): void
    {
        $names = array_column($this->settings(), 'name');

        $this->assertNotContains('blPaymentBasePerLineVat', $names);
    }

    public function testSettings_PerLineVatGroup_IsNotDeclared(): void
    {
        $groups = array_map(static fn (array $s): string => $s['group'] ?? '', $this->settings());

        $this->assertNotContains('per_line_vat', $groups);
    }
}
