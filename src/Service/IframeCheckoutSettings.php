<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Service;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Facade\ModuleSettingServiceInterface;
use Throwable;

/**
 * Reads the "Use iframe instead of checkout button" flag from OXID's
 * ModuleSettingService (the store the admin module-config form writes to).
 *
 * The container lookup is isolated behind the protected readFlag() seam so the
 * default-safe decision logic stays unit-testable without the shop bootstrap.
 */
class IframeCheckoutSettings implements IframeCheckoutSettingsInterface
{
    private const SETTING_NAME = 'blPaymentBaseUseIframe';
    private const MODULE_ID = 'oe_payment_base';

    public function isEnabled(): bool
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
