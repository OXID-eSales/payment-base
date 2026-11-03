# Sprint 18 — VR-LOG: logs carry ids, never content; every webhook delivery is traceable

**Date:** 2026-10-30 (planned) · **Epic:** VR · **Ticket:** tbd
**Branches:** `b-7.4.x-VR-18-log-hygiene` in payment-base, stripe, mollie-payment, paypal.
**Status:** PLANNED · **Priority:** P1 (PII in production logs) · **Depends on:** sprint 16 O5 (payment-base mail log).
**Requirements:** [`_engeneering_requirements.md`](_engeneering_requirements.md) — rule "Logs carry ids, never content".
**Sources:** payment-base report §4 + C4 + D6; mollie report §4.1 (request body), §4.2; stripe report §4.2 L-3, L-7,
D-6; paypal report §4.2, SEC-7.

## Problem

| Where | What leaks / what is missing | Evidence |
|---|---|---|
| payment-base `DoctrineContractOrderMailStore` / `OxidShopOrderService` | raw DBAL message with bound params = whole rendered customer mail | 46 entries ≈105k lines on pay1 (handled in 16 O5 for the mail path; this sprint generalises) |
| mollie `MollieCheckoutSessionHandler.php:210-211` (and every `$e->getMessage()` of a Mollie SDK `ApiException`) | `Request body: {…}` incl. `redirectUrl?contract_token=…`, `cardToken` `tkn_…`, metadata, webhook URL | pay1 10-22 14:38 |
| paypal `PayPalOrderController.php:180,341-343` | raw `Throwable::getMessage()` returned **to the browser** in Smart Buttons mode | SEC-7 |
| payment-base `WebhookPayloadSanitizer.php:26-43` | knows only Stripe PII keys; misses PayPal `payer`, `email_address`, Mollie `consumerAccount`/IBAN | D6 |
| stripe `WebhookController.php:192` + `Service/WebhookLogService` | file log keeps only the action — "skipped" hides *why*; field `payment_intent_id` holds `cs_…` | L-3: cancel/fail/refund 0/291 applied, cause unknowable from the log |
| mollie `WebhookController.php:151-154` | 482 lines with `action`+`success` only — no payment/event/contract id, no reason; `missing_id`/`processor_unavailable` not logged | §4.2 |
| paypal | no provider webhook log at all; `RequestLogService` never wired | §4.2, C-4 |

## Design

| Piece | One job |
|---|---|
| payment-base `Logging\ThrowableSummary` | `fromThrowable(\Throwable): array{class, code, sqlState?, httpStatus?, field?}` — never the message of DBAL/SDK exceptions whose message embeds data. Providers register an extractor for their SDK exception (`MollieApiExceptionSummary`: status + `field`; `PayPalHttpExceptionSummary`: status + `debug_id`; Stripe: `type` + `code` + `request_id`). Tagged `payment_base.throwable_summary`. |
| payment-base `Webhook\WebhookDeliveryLogRecord` | the one shape of a webhook file-log line: `provider, eventId, eventType, objectId, contractId, action, reason, httpCode, durationMs`. `AbstractFileLoggerFactory` writers accept only this record. |
| payment-base `WebhookPayloadSanitizer` | generic rules only (keys containing `secret|token|password|email|iban|address|phone|name`, non-scalar values truncated); providers contribute extra keys through a tagged `WebhookPiiKeyProvider` (moves D6 provider knowledge out of base). |
| paypal `PayPalWebhookFileLoggerFactory` | `log/paypal/paypal_webhooks_<date>.log` on `AbstractFileLoggerFactory`, same record. |

## Stories

| # | Module | Story | Red (first) | Green | Proof |
|---|---|---|---|---|---|
| L1 | payment-base | `ThrowableSummary` + tag; replace `$e->getMessage()` in log contexts of `OxidShopOrderService`, `DoctrineContractOrderMailStore`, `OrderConfirmationMailHandler`, `AbstractWebhookProcessor:86` (also stop returning the message to the PSP — Low item) | `ThrowableSummaryTest`: DBAL exception with params ⇒ summary has `sqlState`, no param values; unknown throwable ⇒ class + code only | service + call-site changes | Integration: forced DBAL failure ⇒ log line contains `sqlState`, `grep` for the fixture e-mail in the log file = 0 |
| L2 | payment-base | `WebhookDeliveryLogRecord`; sanitizer generic + tagged PII keys | `WebhookPayloadSanitizerTest` data provider with a PayPal `payer` payload and a Mollie `consumerAccount` payload ⇒ redacted | record + sanitizer refactor; Stripe keys move to a stripe-side `StripePiiKeyProvider` | consumer test counts unchanged |
| L3 | mollie | Never log SDK messages; enrich `webhook_processed`; log `webhook_rejected` for `missing_id` / `processor_unavailable` | `MollieCheckoutSessionHandlerTest::failureLogHasNoRequestBody` (fixture ApiException with `Request body: {"cardToken":"tkn_x"}` ⇒ no `tkn_` in context); `WebhookControllerTest::logsDeliveryRecord` | `MollieApiExceptionSummary`; controller writes the record | pay1 after deploy: `grep -c 'Request body' oxideshop.log` stays 0; a webhook line shows `eventId`, `contractId`, `reason` |
| L4 | stripe | Record the skip reason; rename `payment_intent_id` → `objectId` | `WebhookLogServiceTest::skipReasonIsLogged`; `…objectIdForCheckoutSession` | use the record; `WebhookResult::$reason` propagated (D-6) | pay1: `log/stripe` lines for `payment_intent.canceled` carry a reason → feeds sprint 23 S-L3 diagnosis |
| L5 | paypal | Provider webhook file log; sanitized browser errors (SEC-7) | `PayPalWebhookFileLoggerFactoryTest`; `PayPalOrderControllerTest::smartButtonsErrorIsGeneric` (adapter exception with debug body ⇒ JSON `{error:'payment_failed'}`) | factory + `WebhookController` writes record; `onError()` returns code + translated generic message, details to debug log (same as `PayPalPaymentHandler::failed()`) | Playwright: Smart Buttons with a forced sandbox error shows the generic message; `log/paypal/paypal_webhooks_<date>.log` exists on pay1 after one sandbox delivery |
| L6 | all | Log-content guard test | per module an Integration test `NoPiiInLogsTest` that runs the happy checkout + one failure with fixture PII and asserts the shop log and provider log contain none of: fixture e-mail, street, `tkn_`, `contract_token=`, `client_secret` | — | CI green in all four repos |

## Definition of done

All six stories merged (payment-base first); pay1 logs after one day of traffic: zero fixture/customer e-mails,
zero `Request body`, every webhook line has ids + reason. Reports in `done/`.

## Out of scope

OPC address/e-mail logging (OPC ticket); log shipping/retention policy.
