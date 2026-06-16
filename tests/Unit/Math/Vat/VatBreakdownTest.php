<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Math\Vat;

use OxidEsales\PaymentBase\Math\Vat\VatBreakdown;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(VatBreakdown::class)]
class VatBreakdownTest extends TestCase
{
    public function testVatForMissingRateReturnsZero(): void
    {
        $breakdown = new VatBreakdown([]);

        $this->assertSame(0.0, $breakdown->vatForRate(19.0));
    }

    public function testTotalVatSumsAllRates(): void
    {
        $breakdown = new VatBreakdown(['19' => 19.0, '7' => 7.0]);

        $this->assertEqualsWithDelta(26.0, $breakdown->totalVat(), 0.0000001);
    }

    public function testRatesReturnsKeys(): void
    {
        $breakdown = new VatBreakdown(['19' => 19.0, '7' => 7.0]);

        $rates = $breakdown->rates();

        $this->assertContains(19.0, $rates);
        $this->assertContains(7.0, $rates);
    }

    public function testImmutableNoSetters(): void
    {
        $ref = new ReflectionClass(VatBreakdown::class);

        $setters = array_filter(
            $ref->getMethods(\ReflectionMethod::IS_PUBLIC),
            static fn(\ReflectionMethod $m) => str_starts_with($m->getName(), 'set')
        );

        $this->assertEmpty($setters, 'VatBreakdown must have no public setter methods');
    }
}
