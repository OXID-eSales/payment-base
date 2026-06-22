<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Contract;

use DateTimeImmutable;
use DateTimeInterface;
use DomainException;
use InvalidArgumentException;
use OxidEsales\PaymentBase\Math\Money\Money;

/**
 * Capture / refund tracking extracted off PaymentContract (Sprint 01a).
 *
 * Owns the four fields that record provider money movement
 * (capturedAmount, refundedAmount, capturedAt, refundedAt) and the
 * state-guarded mutation logic that goes with them. Pulling them out
 * of PaymentContract lets the aggregate stay below its PHPMD class
 * complexity threshold and gives refund-aware query methods a single
 * cohesive home.
 */
class CaptureRefundTracker
{
    private ?float $capturedAmount = null;
    private ?float $refundedAmount = null;
    private ?DateTimeInterface $capturedAt = null;
    private ?DateTimeInterface $refundedAt = null;

    public function getCapturedAmount(): ?float
    {
        return $this->capturedAmount;
    }

    public function getRefundedAmount(): ?float
    {
        return $this->refundedAmount;
    }

    public function getCapturedAt(): ?DateTimeInterface
    {
        return $this->capturedAt;
    }

    public function getRefundedAt(): ?DateTimeInterface
    {
        return $this->refundedAt;
    }

    /**
     * STRP-AUTOCAP-REFUND: webhook delivery order at the PSP is not
     * guaranteed. A `payment_intent.succeeded` can arrive BEFORE
     * `checkout.session.completed` has moved the contract through
     * ready_to_commit → committed. The captured amount field is the
     * source of truth for "money was taken from the customer" — the
     * act of receiving the success webhook is itself the evidence.
     * Refusing the write because the FSM hasn't caught up loses that
     * evidence permanently (the shop ack's the webhook and Stripe
     * won't redeliver). Accept the write in any state where payment
     * could plausibly have occurred at the PSP; reject only DRAFT /
     * NOT_FINISHED (no checkout yet) and the non-fulfilled terminal
     * states (cancelled / expired / failed — money would never have
     * moved on those).
     */
    public function setCapturedAmount(ContractState $state, float $amount): void
    {
        if (!self::stateAllowsCapture($state)) {
            throw new DomainException(sprintf(
                'Cannot set captured amount in state %s',
                $state->getValue(),
            ));
        }
        self::assertPositiveFinite($amount, 'Captured amount');
        $this->capturedAmount = $amount;
    }

    public function addRefundedAmount(ContractState $state, float $amount): void
    {
        if (!$state->isFulfilled()) {
            throw new DomainException('Can only add refunded amount in FULFILLED state');
        }
        self::assertPositiveFinite($amount, 'Refund amount');
        $this->refundedAmount = ($this->refundedAmount ?? 0.0) + $amount;
    }

    public function setCapturedAt(DateTimeInterface $date): void
    {
        $this->capturedAt = $date;
    }

    public function setRefundedAt(DateTimeInterface $date): void
    {
        $this->refundedAt = $date;
    }

    /**
     * Money left to refund on this contract.
     *
     * - `null` when the captured amount is not yet reported (e.g. capture
     *   webhook hasn't fired). Callers MUST treat `null` as "unknown",
     *   never as "0.0" — see {@see CaptureRefundTracker::isFullyRefunded()}
     *   for the symmetric guard on the boolean side.
     * - `max(0.0, captured − refunded)` otherwise. Defensive `max()`
     *   protects against migration/reconciliation states where the
     *   refunded total briefly exceeds the captured total.
     * - Sub-epsilon residuals collapse to `0.0` to absorb float noise.
     */
    public function getRemainingRefundableAmount(): ?float
    {
        if ($this->capturedAmount === null) {
            return null;
        }
        $refunded = $this->refundedAmount ?? 0.0;
        $remaining = $this->capturedAmount - $refunded;
        if ($remaining < Money::HALF_CENT_EPSILON) {
            return 0.0;
        }
        return $remaining;
    }

    /**
     * True iff a positive capture exists AND the refunded total has reached
     * (or exceeded) the captured total within {@see Money::HALF_CENT_EPSILON}.
     *
     * A null captured amount or a zero/missing refunded amount makes this
     * `false` — "nothing has happened yet" is NOT the same as "fully refunded".
     */
    public function isFullyRefunded(): bool
    {
        if ($this->capturedAmount === null || $this->capturedAmount <= 0.0) {
            return false;
        }
        $refunded = $this->refundedAmount ?? 0.0;
        return Money::atLeast($refunded, $this->capturedAmount);
    }

    /**
     * @return array{capturedAmount: float|null, refundedAmount: float|null, capturedAt: string|null, refundedAt: string|null}
     */
    public function toArray(): array
    {
        return [
            'capturedAmount' => $this->capturedAmount,
            'refundedAmount' => $this->refundedAmount,
            'capturedAt'     => $this->capturedAt?->format('Y-m-d H:i:s'),
            'refundedAt'     => $this->refundedAt?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $tracker = new self();
        $tracker->capturedAmount = self::optionalFloat($data, 'capturedAmount');
        $tracker->refundedAmount = self::optionalFloat($data, 'refundedAmount');
        $tracker->capturedAt     = self::optionalDateTime($data, 'capturedAt');
        $tracker->refundedAt     = self::optionalDateTime($data, 'refundedAt');
        return $tracker;
    }

    private static function stateAllowsCapture(ContractState $state): bool
    {
        return $state->isPending()
            || $state->isAuthorized()
            || $state->isReadyToCommit()
            || $state->isCommitted()
            || $state->isFulfilled();
    }

    private static function assertPositiveFinite(float $amount, string $label): void
    {
        if (!is_finite($amount) || $amount <= 0) {
            throw new InvalidArgumentException(sprintf('%s must be a positive finite number', $label));
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function optionalFloat(array $data, string $key): ?float
    {
        if (!isset($data[$key])) {
            return null;
        }
        if (is_int($data[$key]) || is_float($data[$key])) {
            return (float) $data[$key];
        }
        if (is_string($data[$key]) && is_numeric($data[$key])) {
            return (float) $data[$key];
        }
        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function optionalDateTime(array $data, string $key): ?DateTimeInterface
    {
        if (!isset($data[$key]) || !is_string($data[$key]) || $data[$key] === '') {
            return null;
        }
        return new DateTimeImmutable($data[$key]);
    }
}
