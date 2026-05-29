<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation\Guard;

/**
 * Immutable value object returned by a guard when a request must be rejected.
 *
 * `guardName` is for logging only — never serialised into the HTTP body
 * so no fingerprint is exposed to scanners.
 *
 * Sprint 119 (STRP-129) — Phase A2.
 */
final class GuardFailure
{
    public readonly int $httpStatus;
    public readonly string $guardName;

    private function __construct(int $httpStatus, string $guardName)
    {
        $this->httpStatus = $httpStatus;
        $this->guardName = $guardName;
    }

    public static function httpStatus(int $code, string $guardName = ''): self
    {
        return new self($code, $guardName);
    }
}
