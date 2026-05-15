<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Service;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * STRP-AUTOCAP-REFUND Sprint 06 (2026-05-15) — provider-agnostic
 * query: has the payment underlying this contract actually moved
 * money at the PSP, or is it only authorized (hold)?
 *
 * Necessary because the contract state machine collapses these two
 * real-world conditions into the same `committed` state (manual-
 * capture orders never visit `authorized` — STRP-118 fix). Refund-
 * routing decisions must distinguish them; the PSP is the canonical
 * source of truth.
 *
 * Implementations are provider-specific. Each provider (Stripe,
 * PayPal, future) ships its own implementation; payment-base only
 * declares the contract.
 */
interface PaymentCaptureStatusQueryInterface
{
    /**
     * Whether the payment underlying this contract has moved money.
     *
     * - `true`  — PSP confirms money has been captured (e.g. Stripe
     *             PaymentIntent status `succeeded`).
     * - `false` — PSP confirms an authorization-only hold exists, no
     *             money has moved (e.g. Stripe PaymentIntent status
     *             `requires_capture`).
     * - `null`  — Status cannot be determined: PSP unreachable, the
     *             contract is for a provider this implementation does
     *             not handle, no providerOrderId on the contract, or
     *             the PSP returned an unknown status. Callers should
     *             fall back to a conservative default.
     */
    public function isPaymentCaptured(PaymentContractInterface $contract): ?bool;
}
