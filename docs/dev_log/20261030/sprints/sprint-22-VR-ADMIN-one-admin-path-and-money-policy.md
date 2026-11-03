# Sprint 22 — VR-ADMIN: one admin money path, invariants in the aggregate, policy in payment-base

**Date:** 2026-10-30 (planned) · **Epic:** VR · **Ticket:** tbd
**Branches:** `b-7.4.x-VR-22-admin-money-policy` in payment-base, stripe, mollie-payment, paypal.
**Status:** PLANNED · **Priority:** P1 · **Depends on:** sprint 21 (ledger), sprint 17 (dead `PaymentAdapterInterface` /
`AbstractPayment*Service` decision).
**Requirements:** [`_engeneering_requirements.md`](_engeneering_requirements.md).
**Sources:** payment-base S4, S5, S6, S10, D1, D4, D8; stripe D-8, D-3 (admin part); mollie D-M3; paypal SEC-4, C-3,
T-3, D-5.

## Problem

1. **Two admin paths, two protection levels (pb S10).** Classic `PaymentAdminController::dispatchAction()`
   (`:179-205`) moves money with stoken + admin login only; GraphQL `AdminPaymentOperations` (`:46-128`) has
   `#[Right(ADMIN_PAYMENT_CAPTURE/REFUND)]`, step-up MFA, audit and idempotency.
2. **Admin idempotency claim is check-then-act (pb S4).** `OperationIdempotency::claim()` + `DoctrineIdempotencyRepository::save()`
   (SELECT COUNT then UPDATE) hand out two FRESH claims after release/expiry; `findByKey()` returns `null` on DB error
   (= fresh); a different client key bypasses serialization. No per-order lock anywhere.
3. **Invariants not in the aggregate (pb S6).** `CaptureRefundTracker` accepts `refunded > captured` and
   `captured > authorized`.
4. **payment-base does not own capture/refund rules (pb D1/D4).** Fat `PaymentAdapterInterface` (18 methods, only
   Stripe implements); `AbstractPaymentRefundService`/`AbstractPaymentCaptureService` unused (Mollie/PayPal refuse them,
   docblocks say so); three providers reimplement bounds with three sources of truth; PayPal falls back to `'EUR'`,
   Stripe has no remaining-bound in its service.
5. **Stripe direct capture without a contract (D-8).** `StripeCaptureRequestHandler.php:88-104,149-165` →
   `CaptureService::processDirectCapture`; `OrderActionDispatcher.php:134-136` accepts a caller-provided PI.
6. **PayPal capture model (C-3, SEC-4, T-3).** One `METADATA_CAPTURE_ID` overwritten per capture ⇒ refunds always hit
   the last capture, capture dedupe breaks with several partial captures; `PayPal-Request-Id` =
   `{contractId}:checkout|:auth|:capture` reused across continued attempts (OPC-175) ⇒ cached old order/decline.

## Design

| Piece | One job |
|---|---|
| `CaptureRefundTracker` | throws `MoneyInvariantViolation` on `captured > authorized` or `refunded > captured` (with `Money` tolerance). |
| `Payment\CapturePolicy` / `Payment\RefundPolicy` | the agnostic rules: state allows it, amount > 0, ≤ remaining (capturable / refundable), currency = contract currency, per-order lock held. Input: contract + requested `Money`. Output: `PolicyDecision{allowed, reason}`. Used by admin (both paths), GraphQL and `RefundIntentHandler`. |
| `Payment\CaptureGateway` / `Payment\RefundGateway` | narrow provider ports: `capture(contract, Money, idempotencyKey): CaptureResult{providerCaptureId, amount}` / `refund(contract, Money, idempotencyKey, ?providerCaptureId): RefundResult{providerRefundId, amount, status}`. Results are booked through sprint 21's ledgers (+ a `ProviderCaptureLedger` with capture ids, which fixes PayPal C-3). |
| `Payment\MoneyOperationRunner` | one orchestration: lock (`GET_LOCK('pb_order_'.$orderId)`) → atomic idempotency claim → policy → gateway → ledger → audit → release. Both admin paths call it. |
| `OperationIdempotency` | claim = one guarded UPDATE (`… WHERE OXKEY=? AND (OXEXPIRES < now OR OXSTATUS='released')`) or INSERT, affected-rows check; `findByKey()` rethrows. |
| Deleted | `PaymentAdapterInterface` (live today — Stripe is its only implementer; removed here once A5 moves Stripe to the gateways), Stripe `processDirectCapture`. `AbstractPaymentRefundService`, `AbstractPaymentCaptureService`, `PaymentCaptureService` have no subclass/caller at all and are already deleted in sprint 17 (pb batch 5). |

## Stories

| # | Module | Story | Red (first) | Green | Proof |
|---|---|---|---|---|---|
| A1 | payment-base | Tracker invariants | `CaptureRefundTrackerTest::overRefundThrows`, `…overCaptureThrows`, `…toleranceAllowsRounding` | tracker | consumer counts (providers may go red where they relied on over-booking — fix in A5–A7) |
| A2 | payment-base | Atomic admin claim + per-order lock | `OperationIdempotencyTest::twoConcurrentReclaimsOneWins` (two DBAL connections); `…dbErrorIsNotFresh`; `MoneyOperationRunnerTest::differentClientKeysSerialiseOnOrderLock` | `OperationIdempotency`, `DoctrineIdempotencyRepository` (also drop the no-op `catch … throw` Low item), runner | Integration: two parallel GraphQL refunds with different keys ⇒ one PSP call |
| A3 | payment-base | Policies + gateways + runner; classic admin through the runner (S10) | `PaymentAdminControllerTest::captureRequiresPaymentRight`, `…requiresStepUpWhenMfaEnabled`, `…isAudited`, `…doubleSubmitIsDeduped` | classic `dispatchAction()` → runner; `PaymentRightsProvider` checks; audit | Playwright admin (classic tab): user without payment right ⇒ no capture button / refused; double click ⇒ one capture |
| A4 | payment-base | Delete the fat adapter (D1; the templates went in sprint 17) | — | removal of `PaymentAdapterInterface` + the ~6 methods nothing calls (`confirmPayment`, …) | stripe still green (it implemented the interface — A5 lands in the same merge window) |
| A5 | stripe | Gateways; remove direct capture (D-8) | `StripeCaptureGatewayTest`, `StripeRefundGatewayTest`; `OrderActionDispatcherTest::rejectsCallerProvidedPaymentIntent` | delete `processDirectCapture`, caller-PI branch, `PaymentAdapterInterface` implementation | Playwright Stripe admin capture/refund (manual capture mode) |
| A6 | mollie | Gateways; `AdminActionBounds`/`RefundBound` become thin views over the policies (D-M3) | gateway tests; `AdminActionBoundsTest` against policy | delete module-local bound arithmetic | Playwright Mollie admin capture (MOL-30/31 scenarios) |
| A7 | paypal | Gateways with capture list (C-3) + attempt-scoped request ids (SEC-4); fix the `PaymentCaptureCompletedHandler` fallback that still runs for **committed** contracts and adds the captured amount again on redelivery (found by the sprint 17 dead-code check — a behaviour bug, not dead code) | `PaymentCaptureCompletedHandlerTest::redeliveryOnCommittedContractDoesNotAddAgain`; `CaptureFlowTest::twoPartialCapturesThenWebhookForFirstBooksOnce`; `RefundGatewayTest::allocatesAcrossCaptures`; `CheckoutSessionServiceTest::requestIdChangesPerAttempt` | `ProviderCaptureLedger` with capture ids; refunds allocated across captures; request id = PayPal order id (auth/capture) / basket hash + attempt counter (create) | PayPal sandbox: cancel → change basket → checkout again ⇒ new PayPal order with new amount; two partial captures then full refund ⇒ succeeds |

## Definition of done

Both admin paths call `MoneyOperationRunner` (grep: no gateway call outside it). No provider computes refundable /
capturable itself. `PaymentAdapterInterface` gone. Reports + consumer counts in `done/`.

## Risks

A1 may surface existing over-booked contracts on pay1 — run a read-only query first (`refunded > captured`,
`captured > amount`) and list them in `done/` before merging.
