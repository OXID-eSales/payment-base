<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation\Guard;

use OxidEsales\PaymentBase\Validation\FilesystemValidationRuleLoader;
use OxidEsales\PaymentBase\Validation\PluginPathResolverInterface;

/**
 * Guard — rejects an active module that ships no validation rules (e.g. payment-base itself).
 *
 * Sprint 29 (VRL, appendix D of Mythos O-08): such a request used to pass the allowlist and end in the rule
 * loader's "Validation rules file not found" (HTTP 500), after the rate limit had written its row. A malformed
 * rules file is still the loader's 500: that is a misconfiguration, not a caller error.
 *
 * Priority 56: after the allowlist (53, which guarantees a configured module), before the rate limit (60).
 */
final class ValidationRulesGuard implements ValidationGuardInterface
{
    public function __construct(
        private readonly PluginPathResolverInterface $pathResolver,
    ) {
    }

    public function check(ValidationRequestContext $ctx): ?GuardFailure
    {
        $rulesFile = $this->pathResolver->resolvePath($ctx->getPluginModuleId())
            . FilesystemValidationRuleLoader::RULES_RELATIVE_PATH;
        if (is_file($rulesFile)) {
            return null;
        }

        return GuardFailure::httpStatus(422, self::class);
    }

    public function getPriority(): int
    {
        return 56;
    }
}
