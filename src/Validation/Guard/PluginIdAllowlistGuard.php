<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation\Guard;

/**
 * Guard #7 — rejects requests for unknown or inactive plugin module ids.
 *
 * Prevents arbitrary strings being used as pluginModuleId to trigger
 * filesystem reads or rule-loader lookups for non-existent plugins.
 *
 * Priority 70 (last — most expensive, only reached after other guards pass).
 *
 * Sprint 119 (STRP-129) — Phase A2.
 */
class PluginIdAllowlistGuard implements ValidationGuardInterface
{
    public function __construct(
        private readonly ActiveModuleQueryInterface $activeModuleQuery,
    ) {
    }

    public function check(ValidationRequestContext $ctx): ?GuardFailure
    {
        if ($this->activeModuleQuery->isActive($ctx->getPluginModuleId())) {
            return null;
        }

        return GuardFailure::httpStatus(422, self::class);
    }

    public function getPriority(): int
    {
        return 70;
    }
}
