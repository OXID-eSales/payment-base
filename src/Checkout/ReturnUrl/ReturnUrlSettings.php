<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\ReturnUrl;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Facade\ModuleSettingServiceInterface;
use Throwable;

/**
 * Reads `sPaymentBaseHeadlessReturnOrigins` (module settings, group
 * "Headless checkout"): one text field, entries separated by commas, spaces
 * or newlines. An unreadable store answers "none", which the policy turns
 * into "shop only" - the safe side.
 *
 * @since 3.0.0
 */
class ReturnUrlSettings implements ReturnUrlSettingsInterface
{
    public const SETTING_NAME = 'sPaymentBaseHeadlessReturnOrigins';

    private const MODULE_ID = 'oe_payment_base';

    public function getAllowedOrigins(): array
    {
        try {
            $raw = $this->readRaw();
        } catch (Throwable) {
            return [];
        }

        $entries = preg_split('/[\s,]+/', trim($raw)) ?: [];

        return array_values(array_filter($entries, static fn(string $entry): bool => $entry !== ''));
    }

    protected function readRaw(): string
    {
        /** @var ModuleSettingServiceInterface $settings */
        $settings = ContainerFactory::getInstance()
            ->getContainer()
            ->get(ModuleSettingServiceInterface::class);

        return $settings->getString(self::SETTING_NAME, self::MODULE_ID)->toString();
    }
}
