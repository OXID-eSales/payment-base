<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation\RateLimit;

use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Bridge\ModuleSettingBridgeInterface;
use UnexpectedValueException;

/**
 * Reads the admin setting `iValidationApiRatePerMinute` (group "validation", default 30).
 *
 * Sprint 29 (VRL): the setting existed since Sprint 119 but the limit was a literal 30 in services.yaml. Read through
 * the bridge, not the typed facade: OXID stores a saved 'num' setting as a string, which getInteger() rejects. A value
 * that is not a positive number is a misconfiguration and fails the request; a limit of 0 or "no limit" is not guessed.
 */
final class ModuleSettingRateLimitConfig implements RateLimitConfigInterface
{
    public const SETTING_NAME = 'iValidationApiRatePerMinute';

    private const MODULE_ID = 'oe_payment_base';

    public function __construct(
        private readonly ModuleSettingBridgeInterface $settings,
    ) {
    }

    public function getLimitPerMinute(): int
    {
        $raw = $this->settings->get(self::SETTING_NAME, self::MODULE_ID);
        if (!is_numeric($raw) || (int) $raw < 1) {
            throw new UnexpectedValueException(sprintf('Module setting %s must be a positive number', self::SETTING_NAME));
        }

        return (int) $raw;
    }
}
