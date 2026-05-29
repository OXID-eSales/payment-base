<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation\Guard;

/**
 * Narrow interface for verifying the OXID session challenge (CSRF) token.
 *
 * The production implementation wraps `Registry::getSession()->checkSessionChallenge()`.
 * In unit tests a stub returns true/false without booting OXID.
 *
 * Sprint 119 (STRP-129) — Phase A2.
 */
interface SessionChallengeVerifierInterface
{
    /**
     * Returns true when the supplied token matches the session-stored challenge token.
     */
    public function verify(?string $token): bool;
}
