<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Integration\Math;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Facade\ModuleSettingServiceInterface;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\PaymentBase\Math\Vat\PerLineVatCalculator;
use OxidEsales\PaymentBase\Math\Vat\PerLineVatCalculatorInterface;

/**
 * Integration tests for the per-line VAT PriceList override.
 *
 * Verifies that:
 * - PerLineVatCalculatorInterface resolves from the DI container
 * - With blPaymentBasePerLineVat=false (default), getVatInfo() delegates to parent
 * - With blPaymentBasePerLineVat=true (ON), getVatInfo() returns per-line rounded
 *   amounts that diverge from the core grouped calculation
 *
 * @group integration
 */
class PerLineVatPriceListTest extends IntegrationTestCase
{
    public function testCalculatorResolvesFromContainer(): void
    {
        $container = ContainerFactory::getInstance()->getContainer();

        $calculator = $container->get(PerLineVatCalculatorInterface::class);

        $this->assertInstanceOf(PerLineVatCalculator::class, $calculator);
    }

    public function testCalculatorProducesCorrectNetVat(): void
    {
        $container = ContainerFactory::getInstance()->getContainer();
        /** @var PerLineVatCalculatorInterface $calculator */
        $calculator = $container->get(PerLineVatCalculatorInterface::class);

        $breakdown = $calculator->calculate(
            [new \OxidEsales\PaymentBase\Math\Vat\TaxableLine(100.0, 19.0)],
            true
        );

        $this->assertEqualsWithDelta(19.0, $breakdown->vatForRate(19.0), 0.0000001);
    }

    public function testPriceListIsExtendedByModule(): void
    {
        /** @var \OxidEsales\Eshop\Core\PriceList $priceList */
        $priceList = oxNew(\OxidEsales\Eshop\Core\PriceList::class);

        $this->assertInstanceOf(\OxidEsales\PaymentBase\Eshop\Core\PriceList::class, $priceList);
    }

    public function testPriceListGetVatInfoWithDefaultSettingDelegatesToParent(): void
    {
        // Default: blPaymentBasePerLineVat=false → must delegate to OXID core PriceList
        /** @var \OxidEsales\Eshop\Core\PriceList $priceList */
        $priceList = oxNew(\OxidEsales\Eshop\Core\PriceList::class);

        // Add a real Price object
        $price = oxNew(\OxidEsales\Eshop\Core\Price::class);
        $price->setNettoPriceMode();
        $price->setPrice(100.0);
        $price->setVat(19.0);
        $priceList->addToPriceList($price);

        // With per-line disabled (default), must return core-style array (float values, NOT rounded per-line)
        $vatInfo = $priceList->getVatInfo(true);

        $this->assertIsArray($vatInfo);
        // Core groups by VAT rate string key; key '19' with value 19.0
        $this->assertArrayHasKey('19', $vatInfo);
        $this->assertEqualsWithDelta(19.0, $vatInfo['19'], 0.0000001);
    }

    public function testGetVatInfoWithSettingOnReturnsPerLineAndDiffersFromGrouped(): void
    {
        // Three items at €0.21 net / 19% VAT:
        //   per-line:  round(0.21 * 19/100, 2) = round(0.0399, 2) = 0.04 each → sum = 0.12
        //   grouped:   (0.21 + 0.21 + 0.21) * 19/100 = 0.63 * 0.19 = 0.1197 (no rounding)
        // The two results differ, proving the toggle wires the real calculator.
        $container = ContainerFactory::getInstance()->getContainer();
        /** @var ModuleSettingServiceInterface $settings */
        $settings = $container->get(ModuleSettingServiceInterface::class);

        $settings->saveBoolean('blPaymentBasePerLineVat', true, 'oe_payment_base');

        try {
            /** @var \OxidEsales\Eshop\Core\PriceList $priceList */
            $priceList = oxNew(\OxidEsales\Eshop\Core\PriceList::class);

            for ($i = 0; $i < 3; $i++) {
                $price = oxNew(\OxidEsales\Eshop\Core\Price::class);
                $price->setNettoPriceMode();
                $price->setPrice(0.21);
                $price->setVat(19.0);
                $priceList->addToPriceList($price);
            }

            $vatInfo = $priceList->getVatInfo(true);

            $this->assertIsArray($vatInfo);
            $this->assertArrayHasKey('19', $vatInfo);

            // Per-line sum: 3 × round(0.21 × 19/100, 2) = 3 × 0.04 = 0.12
            $this->assertEqualsWithDelta(0.12, $vatInfo['19'], 0.0000001);

            // Grouped (core) would produce 0.1197 — per-line gives 0.12, difference is 0.0003.
            // Assert the divergence is larger than floating-point noise (1e-9), proving the toggle matters.
            $this->assertGreaterThan(1e-9, abs($vatInfo['19'] - 0.1197));
        } finally {
            $settings->saveBoolean('blPaymentBasePerLineVat', false, 'oe_payment_base');
        }
    }
}
