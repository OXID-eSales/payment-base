<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation\Guard;

/**
 * Guard #2 — rejects requests that exceed body size or field count limits.
 *
 * Limits:
 *  - body > 4096 bytes → 413
 *  - field count > 32 → 413
 *
 * Priority 20.
 *
 * Sprint 119 (STRP-129) — Phase A2.
 */
final class PayloadSizeGuard implements ValidationGuardInterface
{
    private const MAX_BODY_BYTES = 4096;
    private const MAX_FIELD_COUNT = 32;

    public function check(ValidationRequestContext $ctx): ?GuardFailure
    {
        if ($ctx->getBodySize() > self::MAX_BODY_BYTES) {
            return GuardFailure::httpStatus(413, self::class);
        }

        if ($ctx->getFieldCount() > self::MAX_FIELD_COUNT) {
            return GuardFailure::httpStatus(413, self::class);
        }

        return null;
    }

    public function getPriority(): int
    {
        return 20;
    }
}
