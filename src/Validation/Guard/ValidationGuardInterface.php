<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation\Guard;

/**
 * Single-responsibility security guard for the central validation endpoint.
 *
 * Each guard checks one concern (method, size, session, origin, CSRF, rate-limit,
 * plugin-id). Guards are executed in priority order (lowest first). On the first
 * non-null return, the request is rejected with `GuardFailure::httpStatus`.
 *
 * Sprint 119 (STRP-129) — Phase A2.
 */
interface ValidationGuardInterface
{
    /**
     * Inspect the request context.
     *
     * @return GuardFailure|null null means "pass"; non-null means "reject".
     */
    public function check(ValidationRequestContext $ctx): ?GuardFailure;

    /**
     * Guards are sorted ascending by priority (10, 20, 30 …).
     * Lower priority number = checked earlier.
     */
    public function getPriority(): int;
}
