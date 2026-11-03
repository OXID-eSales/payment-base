# Sprint 21 — VR-REFUND: every refund booked once, with the PSP's amount, and restock only when it is due

**Date:** 2026-10-30 (planned) · **Epic:** VR · **Ticket:** tbd
**Branches:** `b-7.4.x-VR-21-refund-booking` in payment-base, stripe, mollie-payment, paypal.
**Status:** PLANNED · **Priority:** P0 (money + inventory corruption in normal merchant use).
**Requirements:** [`_engeneering_requirements.md`](_engeneering_requirements.md) — "Money moves are idempotent by PSP id", "Tests may not pin a bug".
**Sources:** stripe D-1, D-2, S-4, D-10, D-7; mollie S-H1, S-H2, S-M4, S-M6, S-M7, T-H1, T-M1, T-M2; paypal SEC-3,
T-3; payment-base S9, Low (`round($amount, 2)`).

## Problem

| # | Finding | Location |
|---|---|---|
| 1 | **Any refund restocks the whole order.** Every succeeded **or pending** refund, any amount, stornos all lines and returns full quantities. Stripe test pins it (`RefundServiceTest.php:488-490`), Mollie test has only the full-refund case. Mollie hard-wires `useStock: true`. | stripe `Service/RefundService.php:236-243` (verified); mollie `Service/RefundService.php:97` → `OxidStockRestorationService.php:43-75`, `services.yaml:808-815` |
| 2 | **Mollie never sends the idempotency key.** Built and carried, never `setIdempotencyKey()` (verified: no call in `src/`). Double click / retry = second refund or capture. | mollie `Adapter/MollieAdapter.php:92-133` |
| 3 | **PayPal books every admin refund twice** (admin `addRefundedAmount` + `PAYMENT.CAPTURE.REFUNDED` webhook, no refund-id dedupe). | paypal `WebhookHandler/PaymentCaptureRefundedHandler.php:30-46`, `Service/RefundService.php:59-63` |
| 4 | **Stripe admin refund vs `charge.refunded` race**, no lock; empty refund id defeats dedupe; books `$event->getAmount() ?? $contract->getAmount()` (authorized, not refunded). | stripe `ContractRefundRecorder.php:49-76`, `WebhookContractFulfillmentHandler.php:78-127`, `StripeRefundRequestHandler.php:186-200` |
| 5 | **Mollie admin refund vs `refunded` webhook**: stale-save exception after the money moved ⇒ admin retries ⇒ (with #2) second refund. Refund webhook answers `refund_recorded` when nothing was recorded (contract not FULFILLED). Chargebacks recorded cumulatively per delivery; after a chargeback later refunds are deduped away. | mollie `RefundService.php:70-97`, `PaymentRefundedHandler.php:57-65`, `ContractRefundRecorder.php:42-50`, `ChargebackCreatedHandler.php:47-62`, `MollieWebhookProcessor.php:112-118,165-173` |
| 6 | **`RefundIntentHandler` merges correlation context over base keys** and does not bound the amount; `round($amount, 2)`. | payment-base `EventSystem/Handler/RefundIntentHandler.php:139-195` |

## Design

| Piece | One job |
|---|---|
| payment-base `Refund\ProviderRefundLedger` | `book(contractId, providerRefundId, amount, currency, source: admin|webhook|intent): BookingResult{booked|duplicate}` — insert-first on `UNIQUE(OXCONTRACTID, OXPROVIDERREFUNDID)` in the existing transaction table, then `PaymentContract::addRefundedAmount()` under `SELECT … FOR UPDATE` on the contract row, both in one DB transaction. Empty refund id ⇒ `InvalidArgumentException` (no silent dedupe bypass). Same for `ProviderChargebackLedger` (delta = new cumulative − booked cumulative; reversal = negative delta). |
| payment-base `Stock\OxidStockRestorationService` | one implementation of `StockRestorationServiceInterface` (moved from stripe/mollie, DRY): `restoreForFullRefund(orderId)` only; respects `blUseStock`; idempotent per order (flag on the order). Partial refunds never restock (line-level restock is a later, explicit admin feature — out of scope). |
| payment-base `Refund\RefundSettledPolicy` | decides when restock runs: refund **succeeded** (not pending) **and** `remainingRefundable == 0`. Called by the ledger after a `booked` result, regardless of source — so admin and webhook paths behave the same. |

## Stories

| # | Module | Story | Red (first) | Green | Proof |
|---|---|---|---|---|---|
| F1 | mollie | **Hotfix S-H1: send idempotency keys** (ships alone) | `MollieAdapterTest::createRefundSendsIdempotencyKey` / `…createCaptureSendsIdempotencyKey` (replace the `null` key at `:212`); key = per-action nonce from admin form / GraphQL `idempotencyKey`, never `contract:action:amount` | `$client->setIdempotencyKey($key)` per mutating call, reset after | Integration with Mollie test API: same key twice ⇒ one refund |
| F2 | stripe + mollie | **Hotfix restock (D-1 / S-H2)**: correct the pinning tests first | `RefundServiceTest::partialRefundKeepsStock`, `…pendingRefundKeepsStock`, `…fullSucceededRefundRestocksOnce` (stripe and mollie); mollie `…respectsBlUseStockOff` | both modules call restore only for full + succeeded; mollie reads `blUseStock` | Playwright admin: refund 1 € of a 3-line order ⇒ stock and `oxstorno` unchanged; refund the rest ⇒ restocked once |
| F3 | payment-base | `ProviderRefundLedger` + `ProviderChargebackLedger` + `RefundSettledPolicy` + shared `OxidStockRestorationService` | `ProviderRefundLedgerTest`: same id twice ⇒ `duplicate`, amount once; concurrent book (two connections) ⇒ one `booked`; empty id ⇒ exception. `ProviderChargebackLedgerTest`: 30 then 50 cumulative ⇒ +30, +20; reversal 50→0 ⇒ −50 | classes, migration (unique key, order restock flag), `StockRestorationServiceInterface` binding owned by base | consumer counts recorded |
| F4 | paypal | Ledger for admin + webhook refunds (SEC-3) | `RefundFlowTest::adminRefundThenWebhookBooksOnce`; `…webhookFirstThenAdminBooksOnce` | `RefundService` + `PaymentCaptureRefundedHandler` book via ledger with the PayPal refund id (verify `supplementary_data.related_ids` against a live sandbox payload first) | PayPal sandbox: admin refund ⇒ refunded total = refund amount after the webhook arrives |
| F5 | stripe | Ledger (S-4) + book Stripe's `amountRefunded` (D-10) | `RefundFlowTest` both orders; `StripeRefundRequestHandlerTest::booksStripeRefundAmountNotAuthorizedAmount` (partial capture fixture) | delete `ContractRefundRecorder` + local `OxidStockRestorationService`; webhook books per `refunds.data[]` id, fetches refund list when `refunds.data` is absent (new API versions) | Stripe test mode Playwright: partial capture 60/100, refund 60 ⇒ refunded 60, remaining 0, restocked once |
| F6 | mollie | Ledger (S-M4), honest webhook result (S-M7), chargeback deltas (S-M6) | `PaymentRefundedHandlerTest::notFulfilledAnswersSkippedWithReason`; `RefundServiceTest::webhookFirstNoStaleError`; `ChargebackCreatedHandlerTest` deltas + reversal; `RefundBoundTest` (T-M1, new) | recorder returns a result; event identity for refunds after a chargeback includes refund ids | Mollie test API: refund in dashboard on a captured (COMMITTED) contract ⇒ booked; chargeback simulation ⇒ one audit row per delta |
| F7 | payment-base | `RefundIntentHandler` (S9) | `RefundIntentHandlerTest::correlationCannotOverrideBaseKeys`, `…amountBoundedByRemainingRefundable`, `…jpyHasNoDecimals` | `array_merge($correlation, $base)`; bound via `getRemainingRefundableAmount()`; `MinorUnitConverter` | opalreturns test suite green (consumer) |

## Definition of done

Every refund path in every provider books through `ProviderRefundLedger` (grep: no `addRefundedAmount(` outside the
ledger). Restock happens exactly once, only on a full succeeded refund. Reports + consumer counts in `done/`.

## Out of scope

Line-level partial restock UI; dispute evidence handling (Stripe/PayPal disputes are only flagged — sprint 23).
