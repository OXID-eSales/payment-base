<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Facade\ModuleSettingServiceInterface;
use OxidEsales\PaymentBase\Checkout\Contract\SinglePaymentSettingsInterface;
use Throwable;

/**
 * Reads blPaymentBaseAutoAssignSinglePayment from OXID's ModuleSettingService
 * (the store the admin module-config form writes to).
 *
 * The container lookup sits behind the protected readFlag() seam so the
 * default-safe decision stays unit-testable without the shop bootstrap.
 */
class SinglePaymentSettings implements SinglePaymentSettingsInterface
{
    private const SETTING_NAME = 'blPaymentBaseAutoAssignSinglePayment';
    private const MODULE_ID = 'oe_payment_base';

    public function isAutoAssignEnabled(): bool
    {
        try {
            return $this->readFlag();
        } catch (Throwable) {
            return false;
        }
    }

    protected function readFlag(): bool
    {
        /** @var ModuleSettingServiceInterface $settings */
        $settings = ContainerFactory::getInstance()
            ->getContainer()
            ->get(ModuleSettingServiceInterface::class);

        return $settings->getBoolean(self::SETTING_NAME, self::MODULE_ID);
    }
}
