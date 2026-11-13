# Sprint 19 — VR-WEBHOOK: no webhook delivery is ever lost; one claim, one guard chain

**Engineering requirements (binding):** [`dev_log/_engeneering_requirements.md`](../../_engeneering_requirements.md)  
**Date:** 2026-10-30 (planned) · **Epic:** VR · **Ticket:** tbd (follow-up of sprint 13 "webhook retry after failed claim")
**Branches:** `b-7.4.x-VR-19-webhook-claim` in payment-base, stripe, mollie-payment, paypal.
**Status:** PLANNED · **Priority:** P1 · **Depends on:** sprint 18 L2 (delivery record) for logging only.
**Requirements:** [`_engeneering_requirements.md`](_engeneering_requirements.md) — "Catch `Throwable` at process boundaries".
**Sources:** payment-base S1, D7, D9, C1 (webhook part); stripe S-3, D-5, D-6, Low (rate limit/allow-list);
mollie S-M5, S-M2; paypal SEC-2, SEC-8, D-1, T-2, Q-3.

## Problem

1. **Claim leaks on `\Error`.** `payment-base/src/Webhook/AbstractWebhookProcessor.php:80` catches `Exception`
   (`use Exception;`, verified). A `TypeError`, fatal or timeout leaves the row `claimed`; `reclaimFailed()` only takes
   `failed` rows (sprint 13 left "a crashed process blocks forever" explicitly out of scope). Every retry is then
   `200 skipped: Already processed`. The 10-30 stale-container TypeErrors on pay1 are exactly this class of failure.
2. **PayPal never adopted the claim.** `paypal/src/PayPal/Controller/Webhook/WebhookController.php:59-80` +
   `Service/WebhookLogService.php:75-102` claim on `oe_payments_idempotency` and never release on failure ⇒ any
   handler exception loses CHECKOUT.ORDER.APPROVED / CAPTURE.COMPLETED / CAPTURE.REFUNDED for good (SEC-2).
3. **Three dedupe mechanisms** (pb D7): `AbstractWebhookProcessor`+`claimEvent` (Stripe, Mollie); dead
   `WebhookProcessor`+`WebhookIdempotencyChecker` (TOCTOU, saves twice); PayPal's find-then-save.
4. **Guard chain ×3** (`GuardChain`, `Https`, `PayloadSize`, `RateLimit`, `IpAllowlist`, `GuardResult`, `Request`)
   copied in all three providers and diverged. Defects in the copies: PayPal rate limiter keeps buckets in an instance
   property (never limits, SEC-8); Mollie keys on `REMOTE_ADDR` behind Cloudflare (S-M2: forged ids exhaust the budget,
   Mollie gets 429); Stripe allow-list empty + `allowLoopback: true` by default and `REMOTE_ADDR` is always the docker
   gateway on pay1; PayPal trusts `X-Forwarded-Proto` unconditionally and passes all private ranges.
5. **PayPal dispatch** decides by event-type string inside each handler, first match wins; Vault handlers not
   registered (Q-3).

## Design

| Piece | One job |
|---|---|
| `AbstractWebhookProcessor::process()` | `catch (\Throwable)` ⇒ `updateStatus(failed, ThrowableSummary)` ⇒ rethrow `\Error` after marking (so the PSP gets 5xx and retries). |
| `DoctrineWebhookLogRepository::claimEvent()` | additionally re-claims `claimed` rows whose `OXRECEIVEDAT < now - claimTtl` (module setting, default 10 min) in the same guarded UPDATE as sprint 13 (`… WHERE OXEVENTID=? AND (OXSTATUS='failed' OR (OXSTATUS='claimed' AND OXRECEIVEDAT < ?))`). UNIQUE scoped `(OXPROVIDER, OXEVENTID)` (Low item) via migration. |
| `payment-base/src/Webhook/Guard/*` | the single guard chain: `HttpsGuard`, `PayloadSizeGuard`, `RateLimitGuard` (backed by existing `DoctrineRateLimitStore`), `IpAllowlistGuard`, `GuardChain`, `GuardResult`, `WebhookHttpRequest`. Client IP from a `TrustedProxyResolver` (setting: trusted proxy CIDRs + header, e.g. `CF-Connecting-IP`; empty ⇒ `REMOTE_ADDR`). Allow-list and proxy trust are configuration, not code. |
| `payment-base/src/Webhook/AbstractWebhookController` | skeleton: build request → guard chain → `processor->process()` → map `WebhookResult` to HTTP → write `WebhookDeliveryLogRecord`. Providers implement `verify()` (signature / fetch-back) and the handler map only. |
| PayPal | extends `AbstractWebhookProcessor` (claim, failure, re-claim, S1 fix for free); handler map keyed by event type (`WebhookEventDispatcher` in payment-base is reused **only if** it is kept — otherwise a plain `array<string, Handler>` map; see sprint 17 for the dead `WebhookEventDispatcher`). |

## Stories

| # | Module | Story | Red (first) | Green | Proof |
|---|---|---|---|---|---|
| W1 | payment-base | `Throwable` + stale-claim re-claim + scoped unique key | `AbstractWebhookProcessorTest::typeErrorLeavesEventReclaimable`; `DoctrineWebhookLogRepositoryClaimTest::staleClaimedRowIsReclaimed` / `…freshClaimedRowIsRefused`; migration test | processor + repository + migration `Version2026110…` | Integration: handler throws `TypeError` ⇒ 5xx; second delivery ⇒ processed. Stripe + Mollie integration suites green with payment-base from the branch |
| W2 | payment-base | Shared guard chain + `TrustedProxyResolver` + `AbstractWebhookController` | `GuardChainTest`, `RateLimitGuardTest` (state survives a new instance — Doctrine store), `TrustedProxyResolverTest` (CF header honoured only from a trusted CIDR), `IpAllowlistGuardTest` (empty list = allow, private ranges **not** implicitly allowed) | `src/Webhook/Guard/*`, controller skeleton, settings in `metadata.php` | consumer test counts unchanged (additive) |
| W3 | paypal | Adopt `AbstractWebhookProcessor` + shared guards; handler map; register Vault handlers or delete them (sprint 17 decides) | `WebhookControllerTest::handlerThrowsThenRedeliveryIsProcessed` (SEC-2/T-2); `…concurrentClaimSecondGetsDuplicate`; `RateLimitGuard` 121st request ⇒ 429 | delete `src/PayPal/Controller/Webhook/{GuardChain,…}` copies + `Service/WebhookLogService` | PayPal sandbox: force a handler failure (feature flag in test env), PayPal redelivers, order gets paid |
| W4 | stripe | Switch to shared guards/controller skeleton | existing `Webhook*GuardTest`s retargeted to payment-base classes; `StripeWebhookControllerTest` on skeleton | delete copies; `services.yaml` allow-list defaults from config | pay1 `log/stripe` unchanged volume, no 5xx |
| W5 | mollie | Switch to shared guards; proxy-aware rate-limit key (S-M2) | `WebhookRateLimitGuard` behind trusted proxy keys on `CF-Connecting-IP`; forged-id flood from one client IP does not 429 a second IP | delete copies | pay1: Mollie test delivery after deploy processed; `log/mollie` line shows `httpCode 200` |
| W6 | payment-base | Delete the dead pipeline (pb D7/C1/D10) | — (deletion; tests of dead classes go with them) | remove `src/Webhook/{WebhookProcessor,WebhookIdempotencyChecker,WebhookEventDispatcher*,WebhookRequestParser*}` + tests, `AbstractWebhookProcessor::isAlreadyProcessed/logWebhookReceived` — **only if sprint 17 batch did not already** | consumer test counts unchanged |

## Definition of done

A handler that throws any `\Throwable` on any provider is retried by the PSP and processed on redelivery (one
integration test per provider). One guard chain in payment-base, zero copies in providers. Reports in `done/`.

## Out of scope

Retry counters/backoff (the PSP owns the schedule); moving provider signature verification into base.
