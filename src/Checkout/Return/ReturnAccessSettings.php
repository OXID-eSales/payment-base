<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Return;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Facade\ModuleSettingServiceInterface;
use Throwable;

/**
 * Module settings of the return leg (group "Return leg"): `iPaymentBaseReturnTokenTtlHours`
 * (default 24, below 1 means the default) and `blPaymentBaseAcceptLegacyReturnTokens`. An
 * unreadable store answers the strict side: default lifetime, no legacy tokens.
 */
class ReturnAccessSettings implements ReturnAccessSettingsInterface
{
    public const TTL_SETTING = 'iPaymentBaseReturnTokenTtlHours';
    public const LEGACY_SETTING = 'blPaymentBaseAcceptLegacyReturnTokens';

    private const MODULE_ID = 'oe_payment_base';
    private const DEFAULT_TTL_HOURS = 24;
    private const SECONDS_PER_HOUR = 3600;

    public function tokenTtlSeconds(): int
    {
        try {
            $hours = $this->readHours();
        } catch (Throwable) {
            $hours = self::DEFAULT_TTL_HOURS;
        }

        return ($hours >= 1 ? $hours : self::DEFAULT_TTL_HOURS) * self::SECONDS_PER_HOUR;
    }

    public function acceptsLegacyTokens(): bool
    {
        try {
            return $this->readLegacySwitch();
        } catch (Throwable) {
            return false;
        }
    }

    protected function readHours(): int
    {
        return $this->settings()->getInteger(self::TTL_SETTING, self::MODULE_ID);
    }

    protected function readLegacySwitch(): bool
    {
        return $this->settings()->getBoolean(self::LEGACY_SETTING, self::MODULE_ID);
    }

    private function settings(): ModuleSettingServiceInterface
    {
        /** @var ModuleSettingServiceInterface $settings */
        $settings = ContainerFactory::getInstance()
            ->getContainer()
            ->get(ModuleSettingServiceInterface::class);

        return $settings;
    }
}
