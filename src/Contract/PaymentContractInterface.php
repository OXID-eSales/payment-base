<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Contract;

use DateTimeInterface;
use OxidEsales\PaymentBase\Model\ModelInterface;

/**
 * Payment contract capturing purchase intent before order creation.
 *
 * States: DRAFT → PENDING → AUTHORIZED → READY_TO_COMMIT → COMMITTED → FULFILLED
 * Or: CANCELLED | EXPIRED | FAILED
 */
interface PaymentContractInterface extends ModelInterface
{
    public function getState(): ContractState;

    public function getStateValue(): string;

    public function getAmount(): float;

    public function getCurrency(): string;

    public function isInState(string $state): bool;

    public function getOrderId(): ?string;

    public function getProvider(): ?string;

    public function getProviderOrderId(): ?string;

    public function getProviderRedirectUrl(): ?string;

    public function getCreatedAt(): DateTimeInterface;

    public function getUpdatedAt(): DateTimeInterface;

    public function getUserId(): string;

    public function getBasketSnapshot(): BasketSnapshot;

    public function areAllConditionsFulfilled(): bool;

    /**
     * Get all conditions attached to this contract.
     *
     * @return array<ContractCondition>
     */
    public function getConditions(): array;

    /**
     * Cancel the contract.
     */
    public function cancel(string $reason = ''): void;

    /**
     * Expire the contract (timeout).
     */
    public function expire(): void;

    /**
     * Transition contract from DRAFT to NOT_FINISHED, linking the early order.
     *
     * Sprint 133 (F15): its sibling transitionToPending() was already on this
     * interface, so consumers had to narrow to the concrete PaymentContract for
     * this one call — and each of them invented its own reaction when the
     * narrowing failed (one threw, one silently returned false). Completing the
     * pair removes that fork.
     */
    public function transitionToNotFinished(string $orderId): void;

    /**
     * Transition contract from DRAFT to PENDING state.
     */
    public function transitionToPending(): void;

    /**
     * Transition contract from PENDING to AUTHORIZED state.
     *
     * Used when payment is authorized but not yet captured (manual capture mode).
     */
    public function authorize(): void;

    /**
     * Transition contract from AUTHORIZED to READY_TO_COMMIT state.
     *
     * Used when an authorized payment is captured (manual capture mode).
     */
    public function captureAuthorization(): void;

    /**
     * Fulfill a condition on this contract.
     *
     * @param string $type Condition type (e.g., 'payment_authorized')
     * @param array<string, mixed> $data Additional data for the condition
     */
    public function fulfillCondition(string $type, array $data = []): void;

    public function commitToOrder(string $orderId): void;

    public function fulfill(): void;

    /**
     * Fail the contract with a reason.
     *
     * @param string $reason Reason for failure
     */
    public function fail(string $reason): void;

    /**
     * Set payment provider information.
     *
     * @param string $provider Provider name (e.g., 'stripe')
     * @param string $providerOrderId Provider-specific identifier (session ID, payment intent ID)
     * @param string|null $redirectUrl Optional redirect URL for the payment
     */
    public function setProvider(string $provider, string $providerOrderId, ?string $redirectUrl = null): void;

    /**
     * Set a metadata value.
     *
     * Used to store provider-specific data like delivery address hash.
     */
    public function setMetadata(string $key, mixed $value): void;

    /**
     * Get a metadata value.
     *
     * @return mixed The stored value, or null if not set
     */
    public function getMetadata(string $key): mixed;

    /**
     * Get all metadata.
     *
     * @return array<string, mixed>
     */
    public function getAllMetadata(): array;

    // Sprint 8: Capture/Refund tracking (consolidated from order_state)

    /**
     * Get the captured payment amount.
     */
    public function getCapturedAmount(): ?float;

    /**
     * Set the captured payment amount.
     */
    public function setCapturedAmount(float $amount): void;

    /**
     * Get the total refunded amount.
     */
    public function getRefundedAmount(): ?float;

    /**
     * Add to the refunded amount (accumulates multiple refunds).
     */
    public function addRefundedAmount(float $amount): void;

    /**
     * Get the timestamp when payment was captured.
     */
    public function getCapturedAt(): ?DateTimeInterface;

    /**
     * Set the timestamp when payment was captured.
     */
    public function setCapturedAt(DateTimeInterface $date): void;

    /**
     * Get the timestamp of last refund.
     */
    public function getRefundedAt(): ?DateTimeInterface;

    /**
     * Set the timestamp of last refund.
     */
    public function setRefundedAt(DateTimeInterface $date): void;

    /**
     * Money still refundable on this contract.
     *
     * - Returns `null` when the captured amount has not yet been
     *   reported (capture webhook pending). Callers MUST treat
     *   `null` as "unknown" and never as `0.0` — otherwise a contract
     *   with an in-flight capture is incorrectly flagged as fully
     *   refunded.
     * - Returns `max(0.0, captured − refunded)` otherwise, with
     *   sub-half-cent residuals collapsed to `0.0` to absorb float
     *   noise.
     */
    public function getRemainingRefundableAmount(): ?float;

    /**
     * True iff a positive captured amount has been recorded AND the
     * refunded total has reached (or exceeded) it, within a half-cent
     * epsilon.
     *
     * Returns `false` when nothing has been captured yet — "no money
     * moved" is NOT the same as "fully refunded".
     */
    public function isFullyRefunded(): bool;
}
