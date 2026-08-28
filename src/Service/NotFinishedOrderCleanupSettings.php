<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Service;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Bridge\ModuleSettingBridgeInterface;
use Throwable;

/**
 * Reads the "Cleanup period" value (iPaymentBaseCleanupPeriod, in days) from
 * OXID's module setting store — the same store the admin module-config form
 * writes to. Reading `oxconfig` directly would silently diverge from what the
 * merchant sees in that form.
 *
 * Deliberately the *bridge* rather than ModuleSettingServiceInterface: OXID
 * stores a `num` setting as a string, but the service's getInteger() is typed
 * `: int` and therefore throws a TypeError on its own stored value. Since a
 * failed read here falls back to the default, that would have quietly ignored
 * whatever the merchant configured. The bridge hands back the raw value and
 * the coercion happens below, in the open.
 *
 * The container lookup is isolated behind the protected readRawPeriod() seam so
 * the interpretation stays unit-testable without the shop bootstrap, matching
 * the IframeCheckoutSettings convention.
 */
class NotFinishedOrderCleanupSettings implements NotFinishedOrderCleanupSettingsInterface
{
    /**
     * Long enough that a customer who wandered off mid-payment and came back
     * the next day still finds their order, short enough that abandoned rows
     * do not pile up for a month.
     */
    public const DEFAULT_PERIOD_DAYS = 7;

    public const SETTING_NAME = 'iPaymentBaseCleanupPeriod';

    private const MODULE_ID = 'oe_payment_base';

    private const MINIMUM_PERIOD_DAYS = 1;

    public function getCleanupPeriodDays(): int
    {
        try {
            $raw = $this->readRawPeriod();
        } catch (Throwable) {
            // No container (CLI before bootstrap) or no such setting yet
            // (module installed but not re-installed after this release).
            // The caller still needs a usable, conservative number.
            return self::DEFAULT_PERIOD_DAYS;
        }

        if (!is_numeric($raw)) {
            return self::DEFAULT_PERIOD_DAYS;
        }

        $configured = (int) $raw;

        if ($configured < self::MINIMUM_PERIOD_DAYS) {
            return self::DEFAULT_PERIOD_DAYS;
        }

        return $configured;
    }

    /**
     * The stored value exactly as the shop hands it over — string, int or
     * nothing at all. Interpreting it is the caller's job.
     */
    protected function readRawPeriod(): mixed
    {
        /** @var ModuleSettingBridgeInterface $settings */
        $settings = ContainerFactory::getInstance()
            ->getContainer()
            ->get(ModuleSettingBridgeInterface::class);

        return $settings->get(self::SETTING_NAME, self::MODULE_ID);
    }
}
