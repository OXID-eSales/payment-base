<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation\Guard;

/**
 * Guard — rejects requests for unknown or inactive plugin module ids (HTTP 422).
 *
 * Prevents arbitrary strings being used as pluginModuleId to trigger
 * filesystem reads or rule-loader lookups for non-existent plugins.
 *
 * Priority 53: before the rate limit (60). Sprint 29 (VRL, Mythos O-08): at 70 it ran after the rate limit, so
 * every invented plugin id had already written a row into oe_payments_idempotency before it was rejected.
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
        return 53;
    }
}
