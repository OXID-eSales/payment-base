<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation\Guard;

/**
 * Guard #5 — rejects requests with an invalid or missing CSRF token.
 *
 * Delegates token verification to SessionChallengeVerifierInterface so
 * the OXID session dependency can be stubbed in unit tests.
 *
 * Priority 50.
 *
 * Sprint 119 (STRP-129) — Phase A2.
 */
class CsrfTokenGuard implements ValidationGuardInterface
{
    public function __construct(
        private readonly SessionChallengeVerifierInterface $verifier,
    ) {
    }

    public function check(ValidationRequestContext $ctx): ?GuardFailure
    {
        if ($this->verifier->verify($ctx->getCsrfToken())) {
            return null;
        }

        return GuardFailure::httpStatus(403, self::class);
    }

    public function getPriority(): int
    {
        return 50;
    }
}
