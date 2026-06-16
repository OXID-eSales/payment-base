<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Math\Vat;

use OxidEsales\PaymentBase\Math\Vat\TaxableLine;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(TaxableLine::class)]
class TaxableLineTest extends TestCase
{
    public function testConstructsAndExposesAmount(): void
    {
        $line = new TaxableLine(100.0, 19.0);

        $this->assertSame(100.0, $line->amount);
    }

    public function testConstructsAndExposesVatRate(): void
    {
        $line = new TaxableLine(100.0, 19.0);

        $this->assertSame(19.0, $line->vatRatePercent);
    }

    public function testIsReadonly(): void
    {
        $ref = new ReflectionClass(TaxableLine::class);

        $this->assertTrue($ref->isReadOnly());
    }
}
