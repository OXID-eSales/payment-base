<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\EventSystem\Event\Request;

use OxidEsales\PaymentBase\EventSystem\Event\EventInterface;

/**
 * Published-language marker for "I want money back on this contract".
 *
 * External modules (returns, vouchers, fraud, …) implement this on
 * their own domain events to ask payment-base to refund a payment
 * without knowing which verb (refund / cancel-authorization /
 * partial-capture) applies — that decision lives in
 * {@see \OxidEsales\PaymentBase\EventSystem\Handler\RefundIntentHandler}.
 *
 * Sprint 03 (2026-05-19): introduced as the boundary contract that
 * lets consumers stay PSP-agnostic while keeping payment-base
 * consumer-agnostic — neither side imports the other's concrete
 * classes; both sides reference this interface.
 *
 * Well-known correlation keys (informational — implementers may add more):
 *   - `returnId`     — when the intent comes from a return resolution
 *   - `intentId`     — caller-defined idempotency key
 *   - `initiator`    — string label of the source ('opalreturns',
 *                       'admin_stripe_tab', …)
 *
 * Correlation context propagates verbatim into the broker's outbound
 * `EventContext`, surfaces on the provider-side request event, and
 * (when the PSP echoes context back) lands on the resulting
 * `PaymentRefundedEvent` so subscribers can correlate the result with
 * their own state machine.
 */
interface RefundIntentEventInterface extends EventInterface
{
    /**
     * OXID of the order whose payment is being refunded.
     */
    public function getOrderId(): string;

    /**
     * Refund amount. `null` means "full refund of the remaining
     * captured amount" — caller defers the resolution of "how much"
     * to the handler.
     */
    public function getAmount(): ?float;

    /**
     * Free-form reason label for logging / audit. Not used for branching.
     */
    public function getReason(): string;

    /**
     * Caller-defined correlation data that the handler propagates into
     * the broker request `EventContext`.
     *
     * @return array<string, scalar|null>
     */
    public function getCorrelationContext(): array;
}
