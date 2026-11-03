<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Webhook\Guard;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Facade\ModuleSettingServiceInterface;
use Throwable;

/**
 * Reads the shop-wide webhook proxy settings (module settings, group "Webhooks"):
 * `sPaymentBaseTrustedProxies` - addresses / CIDR ranges of the reverse proxies in front of the
 * shop, separated by commas, spaces or newlines - and `sPaymentBaseClientIpHeader`, the header
 * those proxies put the caller's address in. Every provider's webhook rate limit keys on the
 * address this resolves (VR-19). An unreadable store trusts no proxy: REMOTE_ADDR, the safe side.
 */
class TrustedProxySettings
{
    public const PROXIES_SETTING = 'sPaymentBaseTrustedProxies';
    public const HEADER_SETTING = 'sPaymentBaseClientIpHeader';

    private const MODULE_ID = 'oe_payment_base';
    private const DEFAULT_HEADER = 'X-Forwarded-For';

    public function clientIp(): TrustedProxyClientIp
    {
        try {
            $proxies = preg_split('/[\s,]+/', trim($this->readRaw(self::PROXIES_SETTING))) ?: [];
            $header = trim($this->readRaw(self::HEADER_SETTING));
        } catch (Throwable) {
            return new TrustedProxyClientIp();
        }

        return new TrustedProxyClientIp(
            array_values(array_filter($proxies, static fn (string $entry): bool => $entry !== '')),
            $header !== '' ? $header : self::DEFAULT_HEADER,
        );
    }

    protected function readRaw(string $name): string
    {
        /** @var ModuleSettingServiceInterface $settings */
        $settings = ContainerFactory::getInstance()
            ->getContainer()
            ->get(ModuleSettingServiceInterface::class);

        return $settings->getString($name, self::MODULE_ID)->toString();
    }
}
