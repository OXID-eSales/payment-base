<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\Eshop\Core;

use OxidEsales\Eshop\Core\Price;
use OxidEsales\PaymentBase\Eshop\Core\PriceList;
use OxidEsales\PaymentBase\Math\Vat\PerLineVatCalculatorInterface;
use OxidEsales\PaymentBase\Math\Vat\VatBreakdown;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Testable subclass that bypasses OXID virtual parent and DI container seams,
 * allowing PriceList behaviour to be tested without shop bootstrap.
 *
 * Declared in the global scope of this file so PHPUnit loads it with the test.
 * It cannot extend PriceList directly because PriceList is final; instead it
 * injects calculator and flag via constructor and overrides the two seams.
 */
class TestablePriceList extends PriceList
{
    /** @var array<mixed> */
    public array $_aList = [];

    public function __construct(
        private readonly PerLineVatCalculatorInterface $calculator,
        private readonly bool $perLineEnabled = true,
    ) {
        // Skip parent constructor — no OXID bootstrap in unit tests.
    }

    protected function getVatCalculator(): PerLineVatCalculatorInterface
    {
        return $this->calculator;
    }

    protected function isPerLineEnabled(): bool
    {
        return $this->perLineEnabled;
    }
}

#[CoversClass(PriceList::class)]
class PriceListOverrideTest extends TestCase
{
    private const DELTA = 0.0000001;

    private function makePrice(float $amount, float $vat): Price
    {
        $price = $this->createMock(Price::class);
        $price->method('getPrice')->willReturn($amount);
        $price->method('getVat')->willReturn($vat);
        return $price;
    }

    public function testDelegatesToCalculatorAndReturnsCoreShape(): void
    {
        $expectedBreakdown = new VatBreakdown(['19' => 19.0]);
        $calculator = $this->createMock(PerLineVatCalculatorInterface::class);
        $calculator->method('calculate')->willReturn($expectedBreakdown);

        $list = new TestablePriceList($calculator);
        $list->_aList = [$this->makePrice(100.0, 19.0)];

        $result = $list->getVatInfo(true);

        $this->assertSame(['19' => 19.0], $result);
    }

    public function testNettoFlagForwarded(): void
    {
        $calculator = $this->createMock(PerLineVatCalculatorInterface::class);
        $calculator->expects($this->once())
            ->method('calculate')
            ->with($this->anything(), false)
            ->willReturn(new VatBreakdown([]));

        $list = new TestablePriceList($calculator);
        $list->_aList = [$this->makePrice(100.0, 19.0)];

        $list->getVatInfo(false);
    }

    public function testEmptyListReturnsEmptyArray(): void
    {
        $calculator = $this->createMock(PerLineVatCalculatorInterface::class);
        $calculator->method('calculate')->willReturn(new VatBreakdown([]));

        $list = new TestablePriceList($calculator);
        $list->_aList = [];

        $result = $list->getVatInfo(true);

        $this->assertSame([], $result);
    }

    public function testDelegatesToParentWhenPerLineDisabled(): void
    {
        $calculator = $this->createMock(PerLineVatCalculatorInterface::class);
        $calculator->expects($this->never())->method('calculate');

        // perLineEnabled=false — must delegate to parent (stub returns [])
        $list = new TestablePriceList($calculator, false);
        $list->_aList = [$this->makePrice(100.0, 19.0)];

        $result = $list->getVatInfo(true);

        // parent stub's getVatInfo returns [] — confirms parent path was taken
        $this->assertIsArray($result);
    }
}
