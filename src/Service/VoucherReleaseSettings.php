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
 * Reads `blPaymentBaseReleaseVouchersOnOrderEnd` from OXID's module setting
 * store — the same store the admin module-config form writes to.
 *
 * The container lookup sits behind the protected readFlag() seam so the
 * decision stays unit-testable without the shop bootstrap, matching the
 * SingleShippingSettings / NotFinishedOrderCleanupSettings convention.
 *
 * @since 2026-09-03
 */
class VoucherReleaseSettings implements VoucherReleaseSettingsInterface
{
    public const SETTING_NAME = 'blPaymentBaseReleaseVouchersOnOrderEnd';

    private const MODULE_ID = 'oe_payment_base';

    /**
     * An unreadable setting answers ON, unlike the shipping/payment
     * auto-assign flags which answer OFF.
     *
     * Those change what the shopper is shown, so silence means "leave the
     * checkout alone". This one only decides whether a coupon on an order that
     * has already ended goes back to the pool. A broken container is not a
     * reason to quietly keep a customer's voucher, and the release itself is
     * best-effort, so the worst case of guessing ON is a logged no-op.
     */
    public function isReleaseOnOrderEndEnabled(): bool
    {
        try {
            return $this->readFlag();
        } catch (Throwable) {
            return true;
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
