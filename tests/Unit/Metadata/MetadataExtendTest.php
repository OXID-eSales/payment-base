<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;

/**
 * Sprint 27 (RC) — invariant I1: payment-base never changes an OXID total.
 *
 * The module must not chain into OXID's price calculation. The `extend`
 * section of metadata.php is the only place such a chain can be declared,
 * so this test guards the invariant at its single source of truth
 * (see docs/invariants/oxid-totals-win.md).
 */
final class MetadataExtendTest extends TestCase
{
    /** @return array<class-string, class-string> */
    private function extend(): array
    {
        $aModule = [];
        require dirname(__DIR__, 3) . '/metadata.php';

        return $aModule['extend'] ?? [];
    }

    public function testExtend_PriceList_IsNotListed(): void
    {
        $this->assertArrayNotHasKey(
            'OxidEsales\Eshop\Core\PriceList',
            $this->extend(),
            'payment-base must not override OXID\'s VAT/total calculation (invariant I1)'
        );
    }

    public function testExtend_NoPriceOrBasketClass_IsListed(): void
    {
        foreach (array_keys($this->extend()) as $shopClass) {
            $this->assertDoesNotMatchRegularExpression(
                '/\\\\(Price|PriceList|Basket|BasketItem)$/',
                $shopClass,
                "payment-base must not chain into {$shopClass}: OXID's totals are read, never rewritten"
            );
        }
    }
}
