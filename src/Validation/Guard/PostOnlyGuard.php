<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation\Guard;

/**
 * Guard #1 — rejects any request that is not HTTP POST.
 *
 * Priority 10 (first in chain) — cheapest check, no dependencies.
 */
class PostOnlyGuard implements ValidationGuardInterface
{
    public function check(ValidationRequestContext $ctx): ?GuardFailure
    {
        if ($ctx->getMethod() === 'POST') {
            return null;
        }

        return GuardFailure::httpStatus(405, self::class);
    }

    public function getPriority(): int
    {
        return 10;
    }
}
