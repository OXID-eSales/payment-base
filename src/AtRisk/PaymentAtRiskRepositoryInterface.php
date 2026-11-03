<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\AtRisk;

/**
 * Storage of the flags; the write side. Unique on contract + reason + reference, which is what makes raising
 * idempotent and the owner mail exactly-once.
 *
 * @since 1.8.0
 */
interface PaymentAtRiskRepositoryInterface
{
    /**
     * @return bool true when the flag is new; false when the same flag already exists (open or acknowledged)
     */
    public function insertIfAbsent(PaymentAtRisk $flag): bool;

    /**
     * Claims the owner mail of a flag nobody mailed yet. Of two concurrent callers exactly one gets true.
     */
    public function claimOwnerMail(string $contractId, AtRiskReason $reason, string $reference): bool;

    /**
     * Gives the claim back after the mail failed, so the next raise tries again.
     */
    public function releaseOwnerMail(string $contractId, AtRiskReason $reason, string $reference): void;

    /**
     * @return bool true when an open flag was acknowledged
     */
    public function acknowledge(string $contractId, AtRiskReason $reason, string $reference): bool;
}
