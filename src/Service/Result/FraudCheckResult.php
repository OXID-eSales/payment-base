<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Service\Result;

/**
 * Value object representing the result of a fraud check.
 *
 * Sprint 2: Simplified result (pass/fail only, no manual review).
 * Immutable value object following Clean Code principles.
 *
 * @since 1.0.0
 */
final class FraudCheckResult
{
    /**
     * @param bool $passed Whether the fraud check passed
     * @param float $score Risk score (0.0 - 1.0, where 1.0 is highest risk)
     * @param string $reason Reason for failure (empty if passed)
     */
    public function __construct(
        public readonly bool $passed,
        public readonly float $score,
        public readonly string $reason = ''
    ) {
    }

    /**
     * Create a passed result.
     *
     * @param float $score Risk score
     * @return self
     */
    public static function passed(float $score): self
    {
        return new self(true, $score);
    }

    /**
     * Create a failed result.
     *
     * @param float $score Risk score
     * @param string $reason Reason for failure
     * @return self
     */
    public static function failed(float $score, string $reason): self
    {
        return new self(false, $score, $reason);
    }

    /**
     * Check if the fraud check passed.
     *
     * @return bool
     */
    public function isPassed(): bool
    {
        return $this->passed;
    }

    /**
     * Check if the fraud check failed.
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return !$this->passed;
    }
}
