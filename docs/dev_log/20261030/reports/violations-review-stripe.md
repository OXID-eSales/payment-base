# stripe — violations review (2026-10-30)

Scope: `extensions/stripe` @ `b-7.4.x` `b4ae8b0` (236 PHP files in `src/`, 230 test files), cross-checked against `../payment-base`, `../mollie-payment`, `../paypal` for DRY/boundary questions. Read-only, no code changed. Webhook log analysis: pay1 `log/stripe/stripe_webhooks_*.log`, 64 daily files 2026-07-27 → 2026-10-30 (2,897 deliveries). Paths relative to the module (`src/Stripe/…` abbreviated) unless prefixed `payment-base/`.

## Summary

| Severity | Security | Design / quality | Total |
|---|---|---|---|
| High | 1 | 2 | 3 |
| Medium | 5 | 8 | 13 |
| Low | — | — | 15 |

**Top 3 risks**
1. **S-1 (High)** — legacy Payment Element return path (`fnc=stripeReturn` without stoken, `fnc=executeStripePayment`) takes a PaymentIntent id from the request and commits the session's contract + marks the order **paid**, without checking the PI amount or that the PI belongs to that contract. Any succeeded/authorized PI of the same Stripe account works (e.g. the customer's earlier cheap order). No template/JS calls these endpoints any more, but they are reachable.
2. **D-1 (High)** — every refund, including a 1-cent partial and a still-`pending` one, restores stock for **every** order line and sets `oxstorno=1` on all of them (`Service/RefundService.php:236-243`, verified). Inventory and order lines corrupted after any partial refund; Mollie copies this.
3. **L-1/L-2/L-3 (pay1 webhooks)** — stale Stripe endpoints cause 420 signature failures (3 of 4 deliveries on one account, flaring again 10-27..29); pay1's subscription never delivered `checkout.session.completed` / `payment_intent.amount_capturable_updated`; `payment_intent.canceled`, `payment_failed` and `charge.refunded` were **never applied** in 3 months (0/291) and the log hides the skip reason.

---

## 1. Security — High / Medium

| ID | Sev | Location | Issue | Impact | Fix |
|---|---|---|---|---|---|
| S-1 | **High** | `Controller/StripeOrderController.php:117-159` (`executeStripePayment`), `:467-495` (`stripeReturn`, no stoken); `Controller/ControllerRequestHelper.php:43-44`; `EventSystem/Handler/StripePaymentStatusHandler.php:76-140`; `payment-base/src/EventSystem/Handler/PaymentAuthorizedEventHandler.php:75-139` → `ContractCommitmentHandler.php:86-101` | PI id taken from request (`payment_intent_id` / `payment_intent`), preferred over the session value (verified). Handler retrieves that PI and, if captured/authorized, dispatches `PaymentAuthorizedEvent` with the **PI's** amount against the **session's** contract. No `pi.metadata.contract_id` == contract check, no amount check (unlike `ContractCommitService::amountMatches`), no "PI already linked" check → `commitToOrder()` + `markOrderAsPaid()`. | Logged-in customer with any started checkout calls `?cl=order&fnc=stripeReturn&payment_intent=pi_<any paid PI of this account>` → order committed and paid (one PI reused for N orders, or under-amount PI). 3DS branch (`StripePaymentStatusHandler.php:157`) also renders the `client_secret` of an arbitrary PI. (Code path verified; needs e2e confirmation.) | Delete the legacy PE path (no callers in views/JS). If kept: route through `ContractCommitService` (`PaymentConfirmation`, amount + currency check), require `pi.metadata.contract_id === sessionContractId`, never take the PI id from the request. |
| S-2 | Medium | `Webhook/StripeWebhookProcessor.php:63-101`; `Service/WebhookEndpointRegistrar.php:68-82` (`connect: true`); `Webhook/Handler/PaymentIntentSucceededWebhookHandler.php` (`findContract` via metadata) | Registration can fall back to a **platform Connect endpoint** (events of all connected accounts). Processor never checks `event.account` / `livemode` against the shop's account; handlers resolve the contract via `metadata.contract_id`, settable by any merchant on the platform. pay1 logs show events from 4 accounts (L-4). | Another connected merchant could send a signed `payment_intent.succeeded` with `metadata.contract_id=<victim contract>` + matching amount → this shop commits/fulfils with money that went elsewhere. Contract ids are random (hard), but the trust boundary is wrong. (Needs confirmation of pay1 Connect setup.) | Store the shop's `acct_` at registration; reject events with a different `account` or mismatching `livemode` in `parseAndValidateRequest`. |
| S-3 | Medium | `payment-base/src/Webhook/AbstractWebhookProcessor.php:69-88` + `payment-base/src/Repository/DoctrineWebhookLogRepository.php:73-105` | After `claimEvent()` the row is `claimed`; only `catch (Exception)` resets to `failed`. A PHP `Error` (TypeError, null deref — cf. the 10-30 stale-container TypeErrors) leaves it `claimed` forever; `reclaimFailed` only takes `failed`. Stripe's retry gets `200 skipped: Already processed`. | Event silently lost: no commit / fulfil / refund booking. | Catch `Throwable` (or try/finally → `failed`); add a stale-claim reclaim. (Fix belongs in payment-base.) |
| S-4 | Medium | `Service/RefundService.php:85-99`, `EventSystem/Handler/StripeRefundRequestHandler.php:186-200`, `Service/ContractRefundRecorder.php:49-66` vs `Webhook/Handler/WebhookContractFulfillmentHandler.php:78-127` | Two unlocked writers of `refundedAmount`. If `charge.refunded` lands before the admin request books, the webhook books (`:92`), then `ContractRefundRecorder::record()` adds again (`:64`); the transaction-row dedupe (`:76`) runs only after the amount was added. Newer API versions omit `refunds.data` → refund row with empty id (`:112`) defeats dedupe. | Refund double-counted → RefundBound refuses legitimate further refunds; wrong OXPAID / refund status. | One authoritative writer (webhook = Stripe truth), or refund-id-idempotent booking (unique insert first, amount only if inserted) under `SELECT … FOR UPDATE`. |
| S-5 | Medium | `Controller/Admin/StripeConnect.php:73-94` | Onboarding callback receives the secret key (`access_token`) as a request parameter (GET query via redirect) and persists it unchanged; no prefix/mode validation, no payment-rights check (stoken only). | Secret key in web-server/proxy access logs and browser history; any admin user can replace the shop's keys. (Needs confirmation.) | POST callback or one-time code exchange; validate key prefix vs mode; require `PaymentRightsProvider::CONFIG`. |
| S-6 | Medium | `Service/ContractTokenService.php:37-56`; `Service/Return/CheckoutReturnInputsResolver.php:62-74`; `Controller/StripeOrderController.php:430-450` | Return token = HMAC(contractId): no expiry, no user/session binding; key from API secret with fallback to webhook secret (`:45`). Ownership check passes when the current user can't be determined (`:69-71`). | Anyone who sees a return URL can replay `checkoutSuccess` from an anonymous session; key rotation invalidates all in-flight tokens. | Add `exp` + session binding, dedicated secret, drop fallback, reject on unknown ownership. |

Checked and OK: signature verification (raw body, SDK `constructEvent`, 300 s tolerance); guard chain fails closed (503); webhook commit path checks amount + currency; capture/refund send Idempotency-Keys; admin module-config actions check stoken; GraphQL operations carry `#[Right]`; headless `returnUrl` allow-listed; no SQL concatenation; only `|raw` uses are core sid/self-links; webhook file log has no secrets/PII.

---

## 2. Design & code quality — High / Medium

### SOLID

| ID | Sev | Location | Issue | Fix |
|---|---|---|---|---|
| D-3 | Medium | `StripeOrderController.php` (776 lines: CSRF, AGB, AJAX session, three return flows, JSON/error formatting), `StripePaymentHandler.php` (541), `Admin/ModuleConfiguration.php` (509), `ModuleConfigurationService.php` (448), `OxidShopOrderService.php` (414) | God classes (SRP). | One controller/service per flow. |
| D-4 | Medium | 27 `?X $x = null` constructors, e.g. `StripeRefundRequestHandler.php:116,199`, `WebhookContractFulfillmentHandler.php:124`; `AbstractStripeWebhookHandler.php:46,74` | Optional collaborators with inline `new` (DIP); null `contractCommit` silently disables the webhook commit — hidden second paths (violates "one straightforward path"). | Mandatory, DI-wired. |

### DRY / agnostic–gnostic boundary

| ID | Sev | Location | Issue | Fix |
|---|---|---|---|---|
| D-5 | Medium | Webhook guard chain (`GuardChain`, `Https`, `PayloadSize`, `RateLimit`, `IpAllowlist`, interface, result) | Copied in stripe, mollie, paypal; already diverged. | Move to `payment-base/src/Webhook/Guard/`. |
| D-6 | Medium | `Service/WebhookLogService` | Duplicates payment-base's (paypal has a third copy) and drops the `WebhookResult` reason (→ L-3). | Use payment-base service; log the reason. |
| D-7 | Medium | `WebhookContractFulfillmentHandler.php:132-267` (+ 442-line twin in mollie); `OxidStockRestorationService`, `ContractRefundRecorder`, `OxidContractLinkedOrderUpdater`, `BasketBuyabilityValidator`, `OxidUserFieldReader`, `*LatePaymentRefunder`, `OxpaidReconciliation*` | Provider-agnostic contract cancel/fail/expire, order mirroring, refund booking, audit rows and helpers duplicated in stripe + mollie. | Move to payment-base. |
| D-8 | Medium | `StripeCaptureRequestHandler.php:88-104,149-165` → `CaptureService.php:122` `processDirectCapture`; `OrderActionDispatcher.php:134-136` | Second capture path without a contract; accepts a caller-supplied PI — breaks strict 1:1 order↔contract. | Remove. |

### TDD

| ID | Sev | Location | Issue | Fix |
|---|---|---|---|---|
| D-2 | **High** | `tests/…/RefundServiceTest.php:488-490`; `StripePaymentStatusHandlerTest` | The test **pins** the whole-order stock restore (D-1) and has no partial-refund case; no test for foreign PI / amount mismatch / reused PI (S-1). | Failing tests first: partial refund keeps stock; foreign/under-amount PI rejected. |
| D-9 | Medium | capturable-updated handler, `OxidStockRestorationService` | Capturable-updated covered only by its commit test; stock restoration has one test file without partial/repeat cases; nothing covers S-3 (Error claim leak), S-4 (race), S-2 (account filter). | Add them. |

### Clean Code

| ID | Sev | Location | Issue | Fix |
|---|---|---|---|---|
| D-1 | **High** | `Service/RefundService.php:236-243` → `OxidStockRestorationService` | Any succeeded **or pending** refund (any amount) restores stock for all lines + storno. | Restore only on full, succeeded refund, or let the admin choose lines. |
| D-10 | Medium | `StripeRefundRequestHandler.php:198` | Books `$event->getAmount() ?? $contract->getAmount()` — the authorized amount after a partial capture. | Book `RefundResponse::amountRefunded`. |
| D-11 | Medium | `PaymentIntentSucceededWebhookHandler.php:134-165` (`tryMetadataLookupOrLegacy`), `:120` (`amount_received ?? amount`), `ModuleConfigurationService.php:143` (legacy webhook secret) | Legacy fallbacks (no-fallback rule). | Remove. |
| D-12 | Medium | PE path (S-1), duplicate `paid` check `CheckoutSessionCompletedWebhookHandler.php:93`, `ChargeDisputeCreatedWebhookHandler` (event not in catalog), `Adapter/StripeWebhookEvent.php` | Dead / unreachable code. | Delete. |

---

## 3. Low importance

- `StripeWebhookProcessor.php:100` — unhandled event types answered `200 skipped`, never counted/alerted.
- `WebhookLogService.php:94` — field `payment_intent_id` holds `cs_…` ids; rename `object_id`.
- `WebhookLogService.php:42,58` — `catch (\Throwable) {}` swallows logging failures.
- `WebhookController.php:342` — `REMOTE_ADDR` is always the docker gateway → rate limit/allow-list key on one shared address.
- `services.yaml:1674-1678` — IP allow-list empty by default with `allowLoopback: true` → guard effectively off.
- `WebhookController.php:195-202` — stale sweep runs inline after every webhook; belongs in cron/console.
- `WebhookController.php:286-297,348-356` — orphaned docblocks.
- `WebhookContractFulfillmentHandler.php:149,176` — audit rows with amount 0.0 and no provider reference.
- `ChargeDisputeCreatedWebhookHandler` — disputes only logged, never flagged on order/contract.
- `StripeCaptureRequestHandler.php:280` — full stack traces on every capture error.
- `StripeOrderController.php:79-110` — `render()` (GET) clears Stripe session keys and rotates `sess_challenge`.
- `StripeOrderController.php:659-673` — `new BasketBuyabilityValidator` / `new OxidLanguageTranslator` instead of container.
- `StripeWebhookEndpointApi.php:105` — `listAll` reads only the first 100 endpoints.
- `WebhookEndpointRegistrar.php:42-48` — `create()` never looks up an existing endpoint for the URL → duplicates accumulate (L-1).
- `CheckoutSessionService::buildLineItems()` (l.168/200) — no empty-basket guard (6 errors on 10-22, see §4.1).
- Module CLAUDE.md is stale (`WebhookHandler/`, `cl=StripeWebhookController`).

---

## 4. Server logs (pay1)

### 4.1 oxideshop.log
Source: pay1 `log/oxideshop.log`, 2026-10-12 → 2026-10-30 15:36.

| Level | Count | First–Last seen | Still recent? | Message (normalised) | Likely cause / code location | Sev |
|---|---|---|---|---|---|---|
| ERROR (TypeError → 500) | 43 | 10-30 10:18 – 12:15 | yes | `RetryCleanupService::__construct(): Argument #3 ($paidSessions) must be PaidSessionSettler, Monolog\Logger given` | Stale compiled container (`var/cache/container`) after the deploy that added `$paidSessions` (`src/Stripe/Service/RetryCleanupService.php:26`); source + `services.yaml:278-286` are correct. Raised from `Controller/Webhook/WebhookController.php:95` → **all Stripe webhooks 500 for ~2 h** (Stripe retries) | **High** (gone after rebuild) |
| ERROR (TypeError → 500) | 17 | 10-30 10:39 – 11:50 | yes | `CheckoutSessionService::__construct(): Argument #2 ($contracts) must be ContractRepositoryInterface, Monolog\Logger given` | Same stale container (`src/Stripe/Service/CheckoutSessionService.php:43`), via `StripePaymentHandler` ← OPC `PaymentHandlerRegistry` ← OPC `PaymentMethodController:72` → **OPC payment-method list 500 for ~70 min** | High (gone) |
| ERROR (TypeError → 500) | 1 | 10-20 18:02 | no | `WebhookContractFulfillmentHandler::__construct(): Argument #6 ($captureRecorder) must be ContractCaptureRecorder, ContractRefundRecorder given` | Stale container during Sprint 137 deploy (`src/Stripe/Webhook/Handler/WebhookContractFulfillmentHandler.php:36`) | Med (gone) |
| ERROR | 53 | 10-13 12:09 – 10-20 16:23 | no | `Capture audit row skipped: contract has no captured amount` | `src/Stripe/Service/ContractCaptureRecorder.php:60`; fixed by 0930207 "Sprint 137: record the captured amount from the webhook, backfill" (10-20 17:42) — stops right after | Med (fixed) |
| ERROR | 3 + 3 | 10-22 17:32 – 17:33 | no | `Failed to create checkout session: The line_items parameter is required in payment mode` + GraphQL `Stripe could not start the checkout` | Empty `BasketSnapshot` sent to Stripe; `CheckoutSessionService::buildLineItems()` (l.168, loop l.200) has no empty-basket guard; logged l.145 | Low |
| ERROR | 4 | 10-16 | no | `Return URL rejected (origin_not_allowed)` via `Stripe/GraphQL/Controller/StripeCheckout.php:78` | payment-base allow-list (headless port 8443) | Low |

**Interpretation:** no Stripe logic bug is active. All recent 500s are stale-container TypeErrors after deploys (constructor signatures changed in OPC-175 / GRAPH-QL, `var/cache/container` not rebuilt or rebuilt late). The ~2 h webhook outage on 10-30 is the most serious item — the deploy must clear the container cache before taking traffic. Capture-amount issue was real and fixed on 10-20. `cs_test_…`/`pi_…` ids at INFO are identifiers, not secrets — acceptable. Stripe's `services.yaml:471` naming `$earlyOrders` ahead of payment-base caused the whole-shop fatal at 10-30 02:45 (see payment-base report).

**Verdict: Medium** — code OK; deploy procedure needs cache clear + payment-base-first order; add an empty-line-items guard.

### 4.2 log/stripe webhook logs

64 files, 2,897 delivery pairs (`WEBHOOK_RECEIVED` / `WEBHOOK_RESULT`, written by `Service/WebhookLogService.php`). All test mode; `remote_ip` always `172.18.0.1`.

| Event type | Deliveries | Result |
|---|---|---|
| checkout.session.expired | 2,416 | 1,913 skipped, 140 session_expired, 363 sig-fail |
| payment_intent.canceled | 196 | 163 skipped, 33 sig-fail, **0 applied** |
| payment_intent.succeeded | 141 | 54 contract_fulfilled, 72 skipped, 15 sig-fail |
| payment_intent.payment_failed | 125 | 113 skipped, 12 sig-fail, **0 applied** |
| charge.refunded | 15 | 15 skipped, **0 applied** |
| test / unknown | 4 | manual probes 10-02 / 10-22 (EMPTY_PAYLOAD 400, payload_too_large 413, evt_badsig, evt_replay) — guards behaved correctly |
| checkout.session.completed, payment_intent.amount_capturable_updated | **0** | never delivered |

HTTP: 2,470 × 200, 426 × 400, 1 × 413; no 5xx / 429 recorded in this log (the 10-30 10:18–12:15 webhook TypeErrors happened before the file logger ran — see §4.1). No processing duration is logged.

**L-1 — stale endpoints: 3 of every 4 deliveries on one account fail signature.** All 420 real signature failures come from account `…RKy8lrhVfC`. Each of its events arrives 4× in the same second (1 × `SUCCESS: skipped`, 3 × 400) with different `payload_size` (4241 vs 4342) → four endpoints on different API versions.

```
[2026-10-29 11:17:09] WEBHOOK_RESULT {"result":"SUCCESS: skipped","http_code":200,"event_type":"payment_intent.canceled","event_id":"evt_3UVTv5RKy8lrhVfC…"}
[2026-10-29 11:17:09] WEBHOOK_RESULT {"result":"FAILED: No signatures found matching the expected signature for payload","http_code":400,…same event…}
```

Failures by day: 09-02..04: 249; 09-09..11: 24; 09-19: 33; 09-29..30: 15; 10-22..30: 104 (10-27: 39, 10-28: 21, 10-29: 27). Consistent with `WebhookEndpointRegistrar.php:42-48` creating a new endpoint whenever the stored id is lost. Action: delete the 3 stale endpoints.

**L-2 — pay1 subscription stale.** No `checkout.session.completed` or `payment_intent.amount_capturable_updated` in 3 months, though both are in `WebhookEventCatalog.php:32,36` → headless/manual-capture commit path is dead on pay1 and nothing switches `providerOrderId` from `cs_` to `pi_`. Action: re-run endpoint registration (update) and verify `enabled_events` in the Dashboard.

**L-3 — cancel/fail/refund never applied; "skipped" hides why.** `payment_intent.canceled`, `payment_failed`, `charge.refunded` are always `SUCCESS: skipped`; the file log keeps only the action (`WebhookController.php:192`). Likely causes (confirm in `oe_payments_webhooklogs.OXERROR`): cancel/fail look up only `findByProviderOrderId(pi)` (`WebhookContractFulfillmentHandler.php:132-178`) with no metadata fallback while the contract still holds `cs_` (L-2); refunds skipped while the contract isn't FULFILLED. Account `…Dy2vmJsUOt`: 765 deliveries, 100 % skipped, incl. 24 `payment_intent.succeeded`.

**L-4 — four accounts deliver.** `…D514s7wzZp` 1,543 (all 54 fulfilments and 140 expiries applied here), `…Dy2vmJsUOt` 765, `…RKy8lrhVfC` 578, `…DwjZ8KF6fm` 4 (10-27 only). Consistent with a Connect endpoint without account check (S-2).

**L-5 — expiry bursts are the shop's own sweep echoing back.** 08-27: 439 `checkout.session.expired`; 10-16..17: 366, peak 16/min. Contract already terminal → 66 % of all traffic is this echo.

**L-6 — duplicates/replays.** 149 event ids delivered more than once: 133 are the L-1 4× pattern; 7 genuine Stripe retries idempotently skipped after success; one `payment_intent.succeeded` twice in the same second (08-27 09:20:54), both skipped.

**L-7 — no secrets / PII / card data.** grep for e-mails, last4, card, iban, address, `sk_`/`rk_`/`pk_`/`whsec_`: nothing. `event_type`/`event_id` are taken from the unverified payload (JSON-encoded, no injection) — that's why forged ids appear.

---

## 5. Recommended next steps (ordered)

1. **Deploy hygiene (§4.1)** — rebuild `var/cache/container` before taking traffic; deploy payment-base before stripe (10-30: ~2 h of webhook 500s + ~70 min OPC payment list 500s).
2. **S-1** — remove `executeStripePayment` / `stripeReturn` + handlers, or bind them to `ContractCommitService` with metadata + amount check. Failing tests first.
3. **D-1 / D-2** — restore stock only on a full, succeeded refund; fix the pinning test; same fix in mollie; move the logic to payment-base.
4. **pay1 ops (L-1, L-2)** — delete the 3 stale endpoints on `…RKy8lrhVfC`; update the pay1 endpoint with the full event catalog.
5. **S-3** — payment-base: catch `Throwable` so the claim always becomes `failed`; reclaim stale claims.
6. **L-3 / D-6** — log the skip reason; query `oe_payments_webhooklogs`; add the `metadata.contract_id` lookup to cancel/fail.
7. **S-4 / D-10** — single, locked, refund-id-idempotent refund booking; book Stripe's `amountRefunded`.
8. **S-2** — store the shop's `acct_` at registration; reject mismatching `account` / `livemode`.
9. **S-5 / S-6** — Connect callback via POST / code exchange + rights check; contract tokens with expiry + session binding.
10. **D-5 / D-7 / D-8 / D-11** — guard chain and agnostic contract transitions into payment-base; delete direct capture and legacy fallbacks.
11. **D-3 / D-4** — split `StripeOrderController`; make optional collaborators mandatory.
