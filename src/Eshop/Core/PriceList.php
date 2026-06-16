<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 *
 * Per-line VAT math is a derived work of Fresh-Advance/OXID-Per-Line-VAT
 * (MIT, © MB Arbatos Klubas). See sprint-125-strp-157-per-line-vat-math-port.md.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Eshop\Core;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Facade\ModuleSettingServiceInterface;
use OxidEsales\PaymentBase\Math\Vat\PerLineVatCalculatorInterface;

// @phpstan-ignore-next-line (PriceList_parent is an OXID virtual class generated at activation)
class PriceList extends PriceList_parent
{
    protected function getVatCalculator(): PerLineVatCalculatorInterface
    {
        /** @var PerLineVatCalculatorInterface $calculator */
        $calculator = ContainerFactory::getInstance()->getContainer()->get(PerLineVatCalculatorInterface::class);
        return $calculator;
    }

    protected function isPerLineEnabled(): bool
    {
        try {
            /** @var ModuleSettingServiceInterface $settings */
            $settings = ContainerFactory::getInstance()->getContainer()->get(ModuleSettingServiceInterface::class);
            return $settings->getBoolean('blPaymentBasePerLineVat', 'oe_payment_base');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param bool $isNettoMode
     * @return array<string|int,float>
     */
    public function getVatInfo($isNettoMode = true)
    {
        if (!$this->isPerLineEnabled()) {
            return parent::getVatInfo($isNettoMode);
        }

        $mapper = new PriceToTaxableLineMapper();
        /** @var list<\OxidEsales\Eshop\Core\Price> $prices */
        $prices = array_values($this->_aList);
        $lines = array_map([$mapper, 'map'], $prices);
        $breakdown = $this->getVatCalculator()->calculate($lines, (bool) $isNettoMode);

        return $breakdown->toArray();
    }
}
