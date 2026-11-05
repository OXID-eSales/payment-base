# Sprint 26 — VR-AUDIT: the six code defects still open after the VR waves

**Date:** 2026-11-04 (planned) · **Epic:** VR · **Status:** DONE 2026-11-05 — merged into `b-7.4.x`: payment-base 2cebca4, stripe b8db293, mollie cf83f90 (CI green 7.4/7.5/7.6 + secret scan + dead-code gate)
**Repos:** payment-base, mollie-payment, stripe (stripe-wallet), paypal (paypal-payment)
**Base:** `b-7.4.x` — payment-base 1a081dc, stripe c7f7bb7, mollie baaed48, paypal c5fbc27 (all CI-green)
**Requirements:** [`../../20261030/sprints/_engeneering_requirements.md`](../../20261030/sprints/_engeneering_requirements.md)
— TDD (red first), no fallbacks, one order ↔ one contract, logs carry ids not content, dead-code gate green.
**Source:** audit of every High/Medium finding of `../../20261030/reports/violations-review-*.md` against the merged
code (2026-11-04). Everything else is FIXED, MOOT, cleanup-only, or needs pay1 / a sandbox / an owner decision (listed at
the end, not part of this sprint).

## Problem

| # | Finding (report id) | Sev | Where (b-7.4.x) | Why it matters |
|---|---|---|---|---|
| 1 | Mollie commits with the shop's own amount (pb **S2**, mollie **D-H1**) | High | `MollieReturnResolver.php:120-125` passes `$contract->getAmount()` / `getCurrency()`; `MollieWebhookProcessor` hands the contract amount to `ContractCommitService` | payment-base's `ConfirmedAmount` check compares the contract with itself → a payment of the wrong amount/currency at Mollie (tampered basket, partial payment, currency mix-up) commits the order. Stripe and PayPal already pass the PSP's figures. |
| 2 | Mollie chargeback: no at-risk flag, later refunds deduplicated away (mollie **S-M6**, **T-M2**) | Medium | `MollieWebhookProcessor.php:110-123,164-171`: once `amountChargedBack > 0` every delivery is typed `chargedback`, event id `tr_x:chargedback:<cents>` | the merchant gets no badge/mail for a chargeback; a refund issued after a chargeback arrives as a `chargedback` delivery with an unchanged id → `duplicate` → never booked. |
| 3 | Mollie logs SDK error text incl. the request body (mollie **§4.1**, sprint 18 **L3** never landed) + webhook log without ids (mollie **§4.2**) | Medium (PII) | `MollieCheckoutSessionHandler.php:209-212`, `MollieExceptionConverter.php:23`, `MollieOrderController:327`, `MolliePaymentSnapshotProvider:83`, `MolliePaymentHandler:123,133`, `contract->fail($e->getMessage())`; `Controller/Webhook/WebhookController.php:142-161` | Mollie's `ApiException` message embeds the request body (card token `tkn_…`, addresses) → it lands in `oxideshop.log` and on the contract row. Rejected/unprocessable webhooks leave no trace with ids. |
| 4 | Stripe legacy fallbacks (stripe **D-11**) | Medium | `PaymentIntentSucceededWebhookHandler.php:87,104-105,118` (`tryMetadataLookupOrLegacy`, `amount_received` → `amount`), `ModuleConfigurationService.php:119` + `WebhookEndpointKeys::LEGACY_SECRET` (`sStripeWebhookEndpointSecret`), `CaptureService.php:102` (PI id from contract metadata) | silent second paths: a PI is matched by metadata instead of the contract's provider id; an authorised-not-captured PI books the full amount as received; the old single secret keeps verifying. |
| 5 | payment-base fallbacks (pb **C2**, **S3** leftover) | Medium | `EarlyOrderCreationHandler.php:121` (`'unknown_payment'`); `ContractService` accepts a 0.00 total | an order is created with a made-up payment id; a 0.00 contract goes to a PSP that cannot charge it. |
| 6 | `X-Forwarded-Proto` trusted from anyone (paypal **SEC-8**; same in Stripe/Mollie webhook controllers) + private ranges hard-coded in PayPal's allow-list | Medium | PayPal `WebhookController.php:164-181` (`trustProxyHeaders()` always true), Stripe `WebhookController::resolveScheme`, Mollie's controller; PayPal `services.yaml:460-472` | any client can claim HTTPS → the HTTPS guard passes plain-http deliveries; the allow-list lets every private address through on every shop. |

## Design

| Piece | One job |
|---|---|
| Mollie `MollieConfirmedFigures` | reads Mollie's own figures from the payment DTO: `amount` (+ `amountCaptured` once captured) and `currency`; the return resolver and the webhook commit pass THESE to `ReturnResolution` / `PaymentConfirmation`. No fallback to the contract: a payment without amount/currency is refused (`commit refused: provider_amount_missing`). |
| Mollie webhook event identity | the event id is built from what changed, not from a sticky type: `tr_x:refunded:<amountRefunded cents>`, `tr_x:chargedback:<amountChargedBack cents>`, both evaluated on every delivery (a delivery can carry a new refund AND an unchanged chargeback). The processor books refunds through `ProviderRefundLedger` (by Mollie refund id, `listRefunds`) and chargebacks through `ProviderChargebackLedger` (delta) independently of each other. A new chargeback delta > 0 raises `PaymentAtRiskFlag` (`AtRiskReason::DisputeOpened`, reference = payment id). |
| Mollie `MollieApiExceptionSummary` | `payment_base.throwable_summary` extractor: HTTP status, Mollie `field`, request id — never `getMessage()`. Every Mollie log context / stored failure text goes through `ThrowableSummaryInterface`; the shopper/admin sees the module's own fixed sentences. |
| Mollie webhook delivery log | payment-base `WebhookDeliveryLogRecord` (ids + reason, like Stripe/PayPal): also for `missing_id`, `processor_unavailable`, guard refusals. |
| Stripe | contract found only by `providerOrderId` (= PI id) — metadata lookup deleted; `amount_received` only (0 ⇒ nothing received ⇒ not booked); one webhook secret per endpoint (the stored per-endpoint secret) — `LEGACY_SECRET` read path deleted, a one-time migration copies a still-set legacy secret into the per-endpoint key and clears it; `CaptureService` takes the PI id from the contract's `providerOrderId` only. |
| payment-base | `EarlyOrderCreationHandler` throws when the context carries no payment id (wiring error, fails loud); `ContractService` refuses a contract whose total is 0.00 (`ContractTotalsException::zeroTotal`) — **see decision D2**. |
| payment-base `TrustedRequestScheme` | the request scheme: `HTTPS` server var, or `X-Forwarded-Proto` **only when `REMOTE_ADDR` is a trusted proxy** (`TrustedProxySettings`, the same setting `TrustedProxyClientIp` uses). Used by all three webhook controllers (delete their own scheme code). PayPal's private ranges leave `services.yaml`: the allow-list is PayPal's published ranges + the shop's trusted proxies. |

## Stories (one branch per repo, payment-base merges first; providers pin CI to it, then unpin)

| # | Repo | Story | Red (first) | Green | Proof |
|---|---|---|---|---|---|
| A1 | payment-base | `TrustedRequestScheme` | `TrustedRequestSchemeTest`: forwarded proto from an untrusted address ⇒ `http`; from a trusted proxy ⇒ `https`; `HTTPS=on` ⇒ `https` | class + service + settings doc | consumer counts unchanged |
| A2 | payment-base | Fallbacks out (#5) | `EarlyOrderCreationHandlerTest::withoutPaymentIdItThrows`; `ContractServiceTest::zeroTotalIsRefused` (if D2 = refuse) | throw / refusal; CHANGELOG | integration: OPC checkout with a 100 % voucher behaves per D2 |
| M1 | mollie | Commit on Mollie's figures (#1) | `MollieReturnResolverTest::reportsMolliesAmountNotTheContracts`; `…currencyMismatchIsRefused`; webhook: `paidWithDifferentAmountIsNotCommitted` | `MollieConfirmedFigures`; resolver + webhook use it | Mollie test API: pay 10.00 on a 12.00 contract (patched basket) ⇒ not committed, contract `pending`, flag/log |
| M2 | mollie | Chargeback + refund identity (#2) | `MollieWebhookProcessorTest::refundAfterChargebackIsBooked`; `…chargebackRaisesAtRiskOnce`; `…replayIsDuplicate` | event id per change; independent ledgers; at-risk raise | Mollie test API chargeback simulation, then a refund ⇒ both booked once, badge shown |
| M3 | mollie | No SDK text in logs (#3) + delivery log | `MollieApiExceptionSummaryTest`; `NoPiiInLogsTest` (Mollie) with a fixture `ApiException` carrying `tkn_…` and an e-mail | extractor + all call sites + delivery log record | `grep` of the test logs; pay1 after deploy: no `Request body` lines |
| S1 | stripe | Legacy fallbacks out (#4) | `PaymentIntentSucceededWebhookHandlerTest::unknownProviderOrderIdIsSkippedNotMatchedByMetadata`; `…authorisedOnlyBooksNothing`; `ModuleConfigurationServiceTest::legacySecretIsNotRead`; migration test | delete paths; one-time secret migration | Stripe test mode: webhook with the stored secret ⇒ 200; with only the old single secret set before the migration ⇒ migrated, still 200 |
| P1 | stripe, mollie, paypal | Adopt `TrustedRequestScheme` (#6) | per provider: `WebhookController…Test::forwardedProtoFromUntrustedClientIsHttp` | delete own scheme code; PayPal: private ranges out of `services.yaml` | pay1: webhook deliveries still 200 behind Cloudflare once the proxy setting lists Cloudflare (see D4) |

## Decisions (answered 2026-11-04)

Owner answers: **D1** as proposed (`amount`), **D2** refuse 0.00, **D3** migrate once, **D4** "ignore" — defect #6
(`X-Forwarded-Proto` / PayPal private ranges) is **dropped from this sprint**: stories A1 and P1 are not done, **D5** add
`chargeback` to `AtRiskReason`.

| # | Question | My proposal |

| # | Question | My proposal |
|---|---|---|
| D1 | Mollie: compare against `amount` (authorised/paid) — or for paid payments against `amountCaptured`? | `amount` for the commit (authorised or paid); `amountCaptured` only feeds the capture ledger. |
| D2 | A 0.00 contract (100 % voucher / free order) — refuse, or let it through without a PSP? | **Refuse** in payment-base; the shop shows "choose another payment method" (OXID core handles free orders without a PSP). |
| D3 | Stripe `LEGACY_SECRET` — migrate the old single secret once into the per-endpoint key, or just stop reading it (merchant re-creates webhooks)? | **Migrate once**, then delete the read path. |
| D4 | Behind Cloudflare, the forwarded proto is only trusted once the shop lists Cloudflare's ranges in `sPaymentBaseTrustedProxies`. Until then pay1's webhooks would be refused with `tls_required`. OK to require that setting (deploy note), or keep trusting the header when **no** proxy is configured? | **Require the setting** (no fallback); add it to the pay1 deploy checklist and fill it in on pay1 before the deploy. |
| D5 | Chargeback flag reason: reuse `dispute_opened`, or add `chargeback` to `AtRiskReason`? | Add **`chargeback`** (clearer badge text, one more enum case in payment-base A1/A2 branch). |

## Definition of done

- Every red test above committed first and seen red for the right reason; all four repos CI-green (7.4/7.5/7.6 + secret scan + dead-code gate).
- Merged into `b-7.4.x`: payment-base first, providers after with the CI pin reverted.
- Re-audit of findings S2, D-H1, S-M6, §4.1, §4.2, D-11, C2, S3, SEC-8 against the merged code ⇒ FIXED.
- CHANGELOG `## [Unreleased]` entries per story; deploy notes for D3/D4 in the pay1 checklist.

## Out of scope (from the audit, not code-only or cleanup)

pay1 operations (migrations O1, PII purge O2, deploy order O4, alert O7, stale Stripe endpoints L-1/L-2, sweep cron L-5,
trusted-proxy values), live-sandbox proofs (Stripe Connect origin S-2, PayPal ranges/request ids, Mollie late money),
owner items (legacy return-token window S-6/S-M3, Stripe Connect middleware POST S-5, PayPal early-order creation D-3,
Stripe L-3 refunds on non-FULFILLED contracts), and cleanup-only findings (god classes, shared webhook controller base
D-1, Stripe `WebhookLogService`/`BasketBuyabilityValidator`, Mollie `RefundBound` test / stateful flag / services.yaml
size, PayPal D-4/D-5/Q-1/Q-3).

## Outcome (2026-11-05)

| Story | Repo | Commits | Notes |
|---|---|---|---|
| A2 | payment-base | f93be1c | `EarlyOrderCreationHandler` throws without a payment id; `ContractTotalsException::zeroTotal()` refuses a 0.00 contract (providers' handlers turn it into their normal error result; headless: `provider_failed`). |
| D5 | payment-base | 2cebca4 | `AtRiskReason::Chargeback` + en/de admin text. |
| S1 | stripe | 454fb2f, 96cc605, b8db293 | Contract only by PI id; `amount_received` only; PI id for capture only from `providerOrderId`; old single secret migrated once on activation (never overwrites, not across modes) and no longer read. **Owner decision 2026-11-04:** the manual "Webhook Endpoint secret" field stays removed — secrets come only from "Create webhooks". Deploy note: shops that pasted a secret by hand run `oe:module:activate` once. Metadata lookup in `AbstractStripeWebhookHandler::contractOfPaymentIntent/contractOfPayment` stays (Checkout-Session contracts carry the session id until commit). |
| M1 | mollie | 366a6fe | Commit on Mollie's `amount`/`currency` (return resolver + paid/authorized webhook); missing figures ⇒ `provider_amount_missing`. |
| M3 | mollie | 014bff8, 55fbf5f | `MollieApiExceptionSummary` (HTTP status + field), no SDK text in logs/stored failures, `WebhookDeliveryLogRecord` for every delivery; request-id extraction dropped (no PSR-7 in CI, header name unverified). |
| M2 | mollie | be5d26c | Event id per change, refund and chargeback handled independently, `AtRiskReason::Chargeback` raised once per new chargeback delta. |

Still to prove with the Mollie test API / Stripe test mode (not possible in CI): M1 tampered amount, M2 chargeback-then-refund,
M3 no `Request body` line on a live failure; Stripe secret migration on a real shop.
