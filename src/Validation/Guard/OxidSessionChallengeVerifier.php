<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation\Guard;

use OxidEsales\Eshop\Core\Registry;

/**
 * OXID-backed implementation of SessionChallengeVerifierInterface.
 *
 * Wraps `Registry::getSession()->checkSessionChallenge()`. The session
 * reads `stoken` from the current request parameters; our guard extracts it
 * from the POST body and the session method compares it internally.
 *
 * Sprint 119 (STRP-129) — Phase A2.
 */
final class OxidSessionChallengeVerifier implements SessionChallengeVerifierInterface
{
    public function verify(?string $token): bool
    {
        // @phpstan-ignore-next-line — OXID Registry is the documented seam
        return (bool) Registry::getSession()->checkSessionChallenge();
    }
}
