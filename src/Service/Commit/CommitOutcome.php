<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Service\Commit;

/**
 * What became of a {@see PaymentConfirmation}.
 *
 * @since 3.0.0
 */
final readonly class CommitOutcome
{
    /** The chain ran and the contract is COMMITTED now. */
    public const COMMITTED = 'committed';

    /** The contract was already COMMITTED or FULFILLED; nothing was done. Success for the caller. */
    public const ALREADY_PROCESSED = 'already_processed';

    /** The chain ran but another condition keeps the contract open; its handler will finish. Not an error. */
    public const PENDING = 'pending';

    /** Nothing was committed; `$reason` says why (contract_not_found, contract_<state>, amount_mismatch, stale_contract). */
    public const REFUSED = 'refused';

    public function __construct(
        public string $outcome,
        public ?string $orderId = null,
        public ?string $reason = null,
    ) {
    }

    public static function committed(?string $orderId): self
    {
        return new self(self::COMMITTED, $orderId);
    }

    public static function alreadyProcessed(?string $orderId): self
    {
        return new self(self::ALREADY_PROCESSED, $orderId);
    }

    public static function pending(): self
    {
        return new self(self::PENDING);
    }

    public static function refused(string $reason): self
    {
        return new self(self::REFUSED, null, $reason);
    }

    /**
     * True when the contract is committed after this call, whether by it or
     * before it - the question a webhook or a return mutation actually asks.
     */
    public function isSettled(): bool
    {
        return $this->outcome === self::COMMITTED || $this->outcome === self::ALREADY_PROCESSED;
    }
}
