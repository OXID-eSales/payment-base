<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Return;

use InvalidArgumentException;

/**
 * Provider-neutral result of a PSP post-return call.
 *
 * Carries only the shape shared handlers + the state machine need. No raw SDK DTO
 * (decision §9.2) — auditing goes through each module's own log service.
 */
readonly class ReturnResolution
{
    public const OUTCOME_AUTHORIZED = 'authorized';
    public const OUTCOME_READY_TO_COMMIT = 'ready_to_commit';
    public const OUTCOME_ALREADY_PROCESSED = 'already_processed';
    public const OUTCOME_PENDING = 'pending';
    public const OUTCOME_FAILED = 'failed';

    public function __construct(
        public string $outcome,
        public ?string $authorizationId,
        public ?string $providerOrderId,
        public float $amount,
        public string $currency,
        public bool $requiresCapture,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {
        // ALREADY_PROCESSED is successful but carries no fresh auth id (the previous run
        // owns it); enforce the invariant only on first-time successful outcomes.
        $needsAuthId = in_array(
            $this->outcome,
            [self::OUTCOME_AUTHORIZED, self::OUTCOME_READY_TO_COMMIT],
            true,
        );
        if ($needsAuthId && ($this->authorizationId === null || $this->authorizationId === '')) {
            throw new InvalidArgumentException('Authorized/ready_to_commit outcome requires an authorizationId');
        }
    }

    public static function authorized(
        string $authorizationId,
        ?string $providerOrderId,
        float $amount,
        string $currency,
    ): self {
        return new self(
            self::OUTCOME_AUTHORIZED,
            $authorizationId,
            $providerOrderId,
            $amount,
            $currency,
            requiresCapture: true,
        );
    }

    public static function readyToCommit(
        string $authorizationId,
        ?string $providerOrderId,
        float $amount,
        string $currency,
    ): self {
        return new self(
            self::OUTCOME_READY_TO_COMMIT,
            $authorizationId,
            $providerOrderId,
            $amount,
            $currency,
            requiresCapture: false,
        );
    }

    public static function alreadyProcessed(?string $providerOrderId): self
    {
        return new self(
            self::OUTCOME_ALREADY_PROCESSED,
            null,
            $providerOrderId,
            0.0,
            '',
            requiresCapture: false,
        );
    }

    public static function failed(string $errorCode, string $errorMessage, ?string $providerOrderId = null): self
    {
        return new self(
            self::OUTCOME_FAILED,
            null,
            $providerOrderId,
            0.0,
            '',
            requiresCapture: false,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
        );
    }

    public function isSuccessful(): bool
    {
        return in_array(
            $this->outcome,
            [self::OUTCOME_AUTHORIZED, self::OUTCOME_READY_TO_COMMIT, self::OUTCOME_ALREADY_PROCESSED],
            true,
        );
    }
}
