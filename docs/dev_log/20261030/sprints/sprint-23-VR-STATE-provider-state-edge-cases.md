# Sprint 23 — VR-STATE: money that arrives late or leaves early always ends in a defined state

**Engineering requirements (binding):** [`dev_log/_engeneering_requirements.md`](../../_engeneering_requirements.md)  
**Date:** 2026-10-30 (planned) · **Epic:** VR · **Ticket:** tbd
**Branches:** `b-7.4.x-VR-23-state-edges` in payment-base (if a shared "late money" service is extracted), stripe,
mollie-payment, paypal.
**Status:** PLANNED · **Priority:** P1 · **Depends on:** sprint 18 L3/L4 (reasons in the webhook logs, needed to
diagnose S-L3), sprint 21 (ledger), sprint 20 R6 (Mollie one commit path).
**Requirements:** [`_engeneering_requirements.md`](_engeneering_requirements.md).
**Sources:** mollie S-H3, S-M1, §4.2 (02:00 bursts, 10-30 skips); stripe §4.2 L-2, L-3, L-5, S-2, Low (disputes);
paypal SEC-9, C-1, Low (REVERSED/PENDING/dispute handlers); payment-base §4 (FraudCheckHandler warnings).

## Problem

| # | Finding | Location | Effect |
|---|---|---|---|
| 1 | **Mollie `paid` on a CANCELLED/EXPIRED/FAILED contract is stranded.** `advanceToCommitted()` swallows DomainException, handler answers 5xx, Mollie gives up after ~10 retries. Late-money refund only covers *replaced attempts*. Likely trigger: nightly `CleanupNotFinishedOrdersCommand` (payment-base `:89`) cancels contracts whose Mollie payment is still open (the 02:00 `skipped` bursts on 10-10/19/23/24). | mollie `WebhookContractFulfillmentHandler.php:128-156`; payment-base `ContractFulfillmentService.php:41-50` | money taken, no order, no refund |
| 2 | **Mollie authorization expires on a COMMITTED manual-capture contract** ⇒ NoOp "payment already taken"; a test pins it. | mollie `WebhookContractFulfillmentHandler.php:206-213`; test `…IsNoOpWhenThePaymentWasAlreadyTaken` | order looks valid, merchant ships unpaid |
| 3 | **Stripe cancel/fail/refund never applied on pay1** (0/291 in 3 months). Cancel/fail look up only `findByProviderOrderId(pi)` while the contract still holds `cs_` because `checkout.session.completed` never arrived (L-2, fixed in sprint 16 O6); refunds skipped while not FULFILLED. | stripe `WebhookContractFulfillmentHandler.php:132-178` | contracts never fail/cancel from Stripe's side |
| 4 | **Stripe Connect account not checked (S-2).** Platform endpoint receives all connected accounts' events; contract resolved via `metadata.contract_id`; no `account`/`livemode` check. pay1 shows deliveries from 4 accounts. | stripe `Webhook/StripeWebhookProcessor.php:63-101`, `WebhookEndpointRegistrar.php:68-82` | another merchant could commit this shop's contract |
| 5 | **Stripe expiry echo** — 66 % of webhook traffic is the shop's own sweep cancelling sessions and receiving `checkout.session.expired` back. | stripe `WebhookController.php:195-202` (sweep inline after every webhook) | noise, load, hides real signals |
| 6 | **PayPal swallows refused transitions and failed saves** (`catch (Throwable)` around transition **and** `save()`); DENIED on a committed order ⇒ 200, order stays paid. No handlers for `PAYMENT.CAPTURE.REVERSED`/`PENDING`, `CUSTOMER.DISPUTE.*`. | paypal `PaymentCaptureDeniedHandler.php:38-44`, `PaymentCaptureCompletedHandler.php:218-227`, `CheckoutOrderApprovedHandler.php:62-69` | chargebacks/denials invisible |
| 7 | **Disputes only logged** (Stripe `ChargeDisputeCreatedWebhookHandler`, event not in catalog). | stripe | merchant not warned |

## Design

| Piece | One job |
|---|---|
| payment-base `Contract\LateMoneyResolver` | single rule for money on a contract that can no longer take it (terminal state or replaced attempt): refund (captured) or release (authorized) via sprint 22 gateways with idempotency key `late:<providerPaymentId>`, record audit, answer the PSP 200. Generalises Mollie's `settledReplacedAttempt` / `MollieLatePaymentRefunder` and the Stripe/PayPal `*LatePaymentRefunder`s (DRY). |
| payment-base `Contract\PaymentAtRiskFlag` | marks contract + order "payment at risk" (authorization expired on committed, capture denied/reversed, dispute opened): admin badge in the payment panel + order list filter + mail to the shop owner. No automatic cancel (merchant decides). |
| payment-base `CleanupNotFinishedOrdersCommand` | before cancelling a contract locally, asks the provider's `AttemptCloser` to cancel/expire the PSP payment; if the PSP says paid ⇒ commit instead (closes the race at its source). |

## Stories

| # | Module | Story | Red (first) | Green | Proof |
|---|---|---|---|---|---|
| T1 | payment-base | `LateMoneyResolver`, `PaymentAtRiskFlag`, cleanup asks PSP first | `LateMoneyResolverTest` (captured ⇒ refund once; authorized ⇒ release; duplicate delivery ⇒ no second refund); `CleanupNotFinishedOrdersCommandTest::paidAtPspIsCommittedNotCancelled` | services + admin badge (panel provider hook) + owner mail template | Integration: contract cancelled locally, then paid webhook ⇒ refund booked, 200 |
| T2 | mollie | `paid`/`authorized` on terminal ⇒ `LateMoneyResolver` (S-H3); expired authorization on COMMITTED ⇒ `PaymentAtRiskFlag` (S-M1) — **rewrite the pinning test first** | `WebhookContractFulfillmentHandlerTest::paidOnCancelledContractRefunds`; `…expiredAuthorizationOnCommittedFlagsOrder` (replaces `…IsNoOpWhenThePaymentWasAlreadyTaken`) | handler routes; delete `MollieLatePaymentRefunder` in favour of T1 | Mollie test API: cancel contract via cleanup command, then pay the open payment ⇒ refunded; manual-capture order left 1 day past auth expiry (test-mode shortcut) ⇒ "payment at risk" badge |
| T3 | stripe | Cancel/fail lookup by `metadata.contract_id` + Connect/livemode filter (S-2) + dispute flag + sweep out of the webhook request | `PaymentIntentCanceledWebhookHandlerTest::resolvesContractViaMetadataWhileProviderIdIsCheckoutSession`; `StripeWebhookProcessorTest::foreignAccountRejected` / `…livemodeMismatchRejected`; `ChargeDisputeCreatedWebhookHandlerTest::flagsOrder` (+ add to catalog) | store shop `acct_` at registration; sweep moves to `oe:payments:stripe:sweep` cron (Low item) | pay1 after sprint 16 O6 + 18 L4: `log/stripe` shows `payment_intent.canceled` applied with reason; events from foreign accounts answered 200 `ignored_foreign_account` (not 400, so Stripe does not retry forever) |
| T4 | paypal | Explicit transitions (SEC-9/C-1); REVERSED/PENDING/dispute handlers | `PaymentCaptureDeniedHandlerTest::deniedOnCommittedFlagsOrderAndPropagatesSaveError`; `PaymentCaptureReversedHandlerTest`; `CustomerDisputeCreatedHandlerTest` | catch only the transition exception; `canTransitionTo…()` guards; new handlers in the handler map (sprint 19 W3) | PayPal sandbox: simulate capture denied / reversed via webhook simulator ⇒ order flagged |
| T6 | stripe | **Radar fraud check never runs** (sprint 17 finding): `StripeRadarFraudCheckService` reads contract metadata `stripe_payment_intent_id`, which nothing writes — owner decides fix or remove | `StripeRadarFraudCheckServiceTest::readsPaymentIntentFromProviderOrderId` (contract with `pi_` provider id, Radar `elevated` ⇒ review flag) | read the PI from `providerOrderId` (after `cs_`→`pi_` switch) or delete service + `FraudCheckHandler` wiring + params | Stripe test card `4000000000004954` (elevated risk) ⇒ order flagged |
| T5 | stripe + paypal | Replace own late-payment refunders with T1 | existing `*LatePaymentRefunder` tests retargeted | delete module copies | consumer counts |

## Definition of done

No provider answers a PSP with 5xx forever for money on a terminal contract; every "money left / money late"
situation either refunds/releases once or flags the order. Reports in `done/`, incl. the `oe_payments_webhooklogs`
query that explains the Mollie 02:00 bursts and the 10-30 02:27–02:34 skips.
