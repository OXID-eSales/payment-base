<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter\Response;

/**
 * Response from a fraud check operation.
 *
 * Provider-agnostic response for fraud check operations.
 * Sprint 31: Replaces FraudCheckResult with consistent success/failure pattern.
 *
 * Success means the fraud check passed (payment should proceed).
 * Failure means the fraud check failed (payment should be blocked).
 *
 * @since 1.0.0
 */
readonly class FraudCheckResponse
{
    /**
     * Error code marking a response where no screening happened, as opposed to
     * one where screening ran and produced a verdict.
     *
     * Sprint 133 (F1).
     */
    public const CODE_UNSCREENED = 'unscreened';

    /**
     * @param bool $successful Whether the fraud check passed (true = proceed, false = block)
     * @param float $score Risk score (0.0 - 1.0, where 1.0 is highest risk)
     * @param string|null $reason Reason for failure (empty if passed)
     * @param string|null $errorMessage Error message if check failed to execute
     * @param string|null $errorCode Error code if check failed to execute
     */
    private function __construct(
        public bool $successful,
        public float $score,
        public ?string $reason,
        public ?string $errorMessage,
        public ?string $errorCode,
    ) {
    }

    /**
     * Create a successful fraud check response (payment should proceed).
     */
    public static function success(float $score): self
    {
        return new self(
            successful: true,
            score: $score,
            reason: null,
            errorMessage: null,
            errorCode: null,
        );
    }

    /**
     * Create a failed fraud check response (payment should be blocked).
     */
    public static function failure(float $score, string $reason): self
    {
        return new self(
            successful: false,
            score: $score,
            reason: $reason,
            errorMessage: null,
            errorCode: null,
        );
    }

    /**
     * Create a response for "screening did not happen, but nothing is wrong":
     * no payment to screen, or a payment method the provider does not score.
     *
     * Payment proceeds, but the caller can tell this apart from an observed
     * clean score, so no audit record claims a screening that never ran.
     * Sprint 133 (F1): previously these cases returned success(0.0), which on
     * this DTO's documented scale means *maximally clean*.
     */
    public static function unscreened(string $reason): self
    {
        return new self(
            successful: true,
            score: 0.0,
            reason: $reason,
            errorMessage: null,
            errorCode: self::CODE_UNSCREENED,
        );
    }

    /**
     * Create an error response (fraud check itself failed to execute).
     */
    public static function error(string $errorMessage, ?string $errorCode = null): self
    {
        return new self(
            successful: false,
            score: 1.0, // Highest risk when check fails
            reason: 'Fraud check failed to execute',
            errorMessage: $errorMessage,
            errorCode: $errorCode,
        );
    }

    /**
     * Whether screening actually ran and produced a verdict.
     *
     * False for an execution error and for the unscreened cases; the score of
     * such a response carries no information and must not be recorded as one.
     */
    public function isScreened(): bool
    {
        return $this->errorMessage === null && $this->errorCode !== self::CODE_UNSCREENED;
    }

    /**
     * Check if the fraud check passed (payment should proceed).
     */
    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    /**
     * Get the risk score.
     */
    public function getScore(): float
    {
        return $this->score;
    }

    /**
     * Get the failure reason.
     */
    public function getReason(): ?string
    {
        return $this->reason;
    }

    /**
     * Get error message if fraud check failed to execute.
     */
    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * Get error code if fraud check failed to execute.
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }
}
