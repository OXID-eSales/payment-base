<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation\Guard;

/**
 * Guard #3 — rejects requests without an active OXID session.
 *
 * An empty or null session id means the caller has no valid shop session
 * (e.g. a scripted request that never loaded a storefront page).
 *
 * Priority 30.
 *
 * Sprint 119 (STRP-129) — Phase A2.
 */
final class ActiveSessionGuard implements ValidationGuardInterface
{
    public function check(ValidationRequestContext $ctx): ?GuardFailure
    {
        $sessionId = $ctx->getSessionId();

        if ($sessionId === null || $sessionId === '') {
            return GuardFailure::httpStatus(401, self::class);
        }

        return null;
    }

    public function getPriority(): int
    {
        return 30;
    }
}
