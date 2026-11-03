# mollie-payment — violations review (2026-10-30)

Scope: `extensions/mollie-payment` at `origin/b-7.4.x` HEAD `5f9fc0e` (src/ ~195 PHP files, services.yaml, metadata.php, views, JS), cross-checked against payment-base `6fc1f0a`, stripe and paypal checkouts; pay1 webhook logs `log/mollie/mollie_webhooks_2026-08-17 … 2026-10-30` (41 files). Read-only review, no code changed. Paths are relative to the module unless prefixed (`payment-base/…`, `stripe/…`).

## Summary

| Severity | Security / integrity | Design & quality | Total |
|---|---|---|---|
| High | 3 | 2 | 5 |
| Medium | 7 | 12 | 19 |
| Low | — | — | 15 |

**Verified good:** the webhook **never trusts the body**. `WebhookController` reads only `id`, checks its shape (`tr_[A-Za-z0-9]{1,64}`, `src/Mollie/Controller/Webhook/WebhookController.php:67,264-269`), and `MollieWebhookProcessor::parseAndValidateRequest()` fetches the payment again with the secret key (`src/Mollie/Webhook/MollieWebhookProcessor.php:73-81`). All routing comes from the API DTO. Other things that check out: frontend `execute()` keeps the session-challenge (stoken) check (`src/Mollie/Controller/MollieOrderController.php:63,123-126`); admin actions are CSRF-checked in `payment-base/src/Admin/PaymentAdminController.php:191`; every GraphQL entry point has `#[Right]`; payment-base validates headless `returnUrl` (`payment-base/src/Checkout/Headless/HeadlessCheckoutService.php:268`); Mollie's `checkoutUrl` has a host allowlist (`MollieRedirectUrlValidator`); no SQL is built by string concatenation; no `innerHTML` in module JS; the only `|raw` is `getHiddenSid()`.

**Top 3 risks**
1. **Idempotency keys are built but never sent to Mollie** (`src/Mollie/Adapter/MollieAdapter.php:92-133`). Double-submitted or retried admin refunds and captures, and the OPC-175 late-money refund, are not deduplicated by Mollie, and the classic admin has no other double-submit guard.
2. **Any partial refund cancels (stornos) every order line and puts all their stock back** (`src/Mollie/Service/RefundService.php:97` → `src/Mollie/Service/OxidStockRestorationService.php:43-75`). Stripe has the same bug.
3. **A `paid` webhook on a contract that is already cancelled, expired or failed has nowhere to go.** The ladder does nothing, the handler answers 5xx, Mollie retries about 10 times and gives up. The customer's money is taken, there is no order, and nothing refunds it (`src/Mollie/Webhook/Handler/WebhookContractFulfillmentHandler.php:128-156`).

## 1. Security — High / Medium

| ID | Sev | Location | Issue | Impact | Fix |
|---|---|---|---|---|---|
| S-H1 | High | `src/Mollie/Adapter/MollieAdapter.php:92-117` (createCapture), `:120-133` (createRefund); keys built in `src/Mollie/EventSystem/Translator/MollieEventTranslator.php:87-95`, `src/Mollie/Service/MollieLatePaymentRefunder.php:102-107` | `CaptureRequest::$idempotencyKey` / `RefundRequest::$idempotencyKey` are carried all the way through but **never passed to the Mollie client** (no `setIdempotencyKey()`; grep finds no use in Adapter/). The classic admin path (`payment-base/src/Admin/PaymentAdminActionDispatcher`) has no idempotency or lock either. `MollieAdapterTest` passes `null` for the key (`tests/Unit/Adapter/MollieAdapterTest.php:212`), so the gap is untested. | A double-click, browser resubmit, two admins, or a retry after a timeout creates a **second partial refund or capture**. The OPC-175 "refund once" key is ineffective; only the metadata record stops it, and that record is written after the money moves. | Call `$client->setIdempotencyKey($key)` per mutating call (v2.79.1 supports it) and assert it in the adapter test. Use a per-action nonce from the form or GraphQL `idempotencyKey`, not `contract:action:amount`: an amount-derived key would block a legitimate second refund of the same amount within Mollie's window. |
| S-H2 | High (integrity) | `src/Mollie/Service/RefundService.php:97,126-139` → `src/Mollie/Service/OxidStockRestorationService.php:43-75,97-127`; same in `stripe/src/Stripe/Service/RefundService.php:236-238` | `restoreStockIfOrderLinked()` runs after **every** refund, whatever the amount. The restorer stornos **all** order articles and returns their full quantity to stock. Its own docblock says it is for full refunds. `useStock: true` is hard-wired in `services.yaml:808-815`, ignoring the shop's `blUseStock`. | A €5 goodwill refund on a €500 order puts every item back in stock and marks every line storno. This oversells inventory and corrupts order data. Stripe has the same bug. | Restore stock only when the refund brings refundable to 0 (or restore per line when line refunds exist). Read `blUseStock` from config. Move one implementation to payment-base (see DRY table). |
| S-H3 | High (needs confirmation of frequency) | `src/Mollie/Webhook/Handler/WebhookContractFulfillmentHandler.php:128-156`; `payment-base/src/Service/ContractFulfillmentService.php:41-50`; trigger: `payment-base/src/Command/CleanupNotFinishedOrdersCommand.php:89` (cancels the contract locally) | On `paid`, a contract in a terminal state (CANCELLED/EXPIRED/FAILED) is not `isFulfilled()`. `advanceToCommitted()` then swallows every DomainException, `fulfill()` returns false, and the handler answers `Failed` → 5xx. The late-money refund (`settledReplacedAttempt`) only covers **replaced attempts**, not terminal contracts. Mollie keeps payments open after the local cleanup cancels the contract when they cannot be cancelled (`isCancelable=false`, e.g. bank transfer; see `MollieAttemptCloser.php:61-63`). | The customer pays, the shop records nothing, Mollie's retries all fail, and then the money sits on the merchant account with no order and no refund. The only trace is warning logs. | Treat "paid on a terminal contract" like late money: refund (or release) with an idempotency key, record it on the contract, alert, and answer 200. Add a unit test. |
| S-M1 | Medium | `src/Mollie/Webhook/Handler/WebhookContractFulfillmentHandler.php:206-213`; `payment-base/src/Contract/PaymentContract.php:323-324`; pinned by `tests/Unit/Webhook/Handler/WebhookContractFulfillmentHandlerTest.php` (`testHandlePaymentExpired_IsNoOpWhenThePaymentWasAlreadyTaken`) | With manual capture, `authorized` now commits the order (GRAPH-QL MS3, `:304-346`). When that **authorization expires**, the COMMITTED contract gets NoOp "payment already taken", but nothing was captured. | The order stays committed and looks valid in the admin while the hold has been released. The merchant may ship without payment. Possible log trace: the 3 `skipped` deliveries interleaved with expiries on 2026-10-30 02:27–02:34 (needs confirmation). | When committed **and** `capturedAmount === null`/requiresCapture, flag the contract and order (authorization expired), cancel the order or alert the merchant. Change the test to the correct behaviour. |
| S-M2 | Medium | `src/Mollie/Controller/Webhook/WebhookRateLimitGuard.php:54-69`; `WebhookController.php:191-198` | The rate limit is keyed on `REMOTE_ADDR` (120/min). The documented deployment sits behind Cloudflare (`WebhookController.php:44-50`), so every request, Mollie's included, arrives from a handful of edge IPs. | Anyone can exhaust the shared budget with well-formed forged ids, and Mollie's real deliveries then get **429**. Mollie retries and finally drops them, so orders are never finalised. Attackers with many IPs still get one outbound API call per forged id. | When proxy headers are trusted, key on a trusted client-IP header (`CF-Connecting-IP`) or exempt known proxy ranges. Add a second global budget for "unknown id" outcomes and keep the shape check. |
| S-M3 | Medium | `src/Mollie/Service/ContractTokenService.php:35-99` (twin of `stripe/src/Stripe/Service/ContractTokenService.php`) | The return token is `base64(contractId:HMAC)`. It **never expires**, is not bound to a session or user, and its secret is derived from the active API key (`:88-96`). | A leaked return URL (history, Referer, support ticket) stays a permanent bearer credential for `checkoutReturn` and `mollieCheckoutCancel` on that contract. Rotating the key or switching test↔live invalidates every in-flight return (shoppers land on `invalid_token`). | Move one token service to payment-base with its own secret, `exp` in the signed payload, and an optional user binding. Providers keep only the salt. |
| S-M4 | Medium (needs confirmation) | `src/Mollie/Service/RefundService.php:70-97` vs `src/Mollie/Webhook/Handler/PaymentRefundedHandler.php:57-63` | The admin refund loads the contract, creates the Mollie refund, and only then saves the recorded amount. Mollie's `refunded` webhook can arrive first, record the delta, and bump the version. The admin save then throws `StaleContractException` **after** the money moved. | The admin sees an error and no audit row or stock action happens, so the admin is likely to retry. Combined with S-H1 that retry is a second refund. | Make recording after the API call idempotent: reload and record `max(0, mollie.amountRefunded − contract.refunded)` with a retry on stale, as the webhook does. Never surface it as a failure once Mollie has accepted the refund. |
| S-M5 | Medium (payment-base, affects Mollie) | `payment-base/src/Webhook/AbstractWebhookProcessor.php:78-88`; `payment-base/src/Repository/DoctrineWebhookLogRepository.php:73-105` | `processEvent()` catches `Exception` only. A `TypeError`/`Error`, a fatal, or a max-execution timeout leaves the event row in **CLAIMED**, and only `failed` rows can be claimed again. | Mollie's retry is answered `200 skipped "Already processed"`, so a `paid` event is lost for good. | Catch `Throwable`, and let a CLAIMED row older than N minutes be claimed again. |
| S-M6 | Medium | `src/Mollie/Webhook/Handler/ChargebackCreatedHandler.php:47-62`; `src/Mollie/Webhook/MollieWebhookProcessor.php:112-118,165-173` | (a) Each chargeback delivery records the **cumulative** `amountChargedBack` as a new audit row (`:55`). (b) The contract and order are not touched. (c) Once any chargeback exists, every later webhook is typed `chargedback`, so the event id only changes when the chargeback amount changes. | Chargebacks are double-counted in the audit log, the merchant is not alerted on the order, refunds and reversals after a chargeback are deduplicated away and never recorded, and a reversal (lower amount) is booked as another chargeback. | Record deltas against a stored chargeback total. Build the event id from status, refunded cents and charged-back cents together, and route refunds independently. Flag the order. |
| S-M7 | Medium | `src/Mollie/Webhook/Handler/PaymentRefundedHandler.php:63-65` + `src/Mollie/Service/ContractRefundRecorder.php:42-50` | When the contract is not FULFILLED (e.g. COMMITTED partial capture, or AUTHORIZED), the recorder logs a warning and returns. The handler still answers `success('refund_recorded')`, and the event is marked processed. | A refund made in the Mollie dashboard is lost from shop bookkeeping with no retry. The webhook log claims it was recorded. | Have the recorder return a result. Answer `skipped` with the reason, or record on any captured state. |

## 2. Design & code quality — High / Medium

### SOLID
- **D-H1 (High): two commit paths, plus a "fallback" constructor.** `src/Mollie/Webhook/Handler/WebhookContractFulfillmentHandler.php:58,273-286` takes an optional `?ContractCommitServiceInterface $contractCommit = null`. When it is set, `authorized` goes through payment-base's `ContractCommitService`. When it is not, a legacy ladder (`advanceToAuthorized`) runs. Meanwhile `paid` **always** climbs a hand-written ladder (`advanceToCommitted`, `:383-406`) that re-implements what ContractCommitService does, without its amount/currency check. This breaks the team rule "one straightforward path, no fallbacks". Fix: make the dependency required, drop the legacy branch, and route `paid` through `ContractCommitService` followed by `ContractFulfillmentService`.
- **D-M1: SRP — `WebhookContractFulfillmentHandler` (442 lines).** It holds 5 status ladders, the commit integration, late-money settlement, audit recording, order mirroring and stale-retry. Fix: one small class per status behind the existing `MollieWebhookEventHandlerInterface`, plus a shared `AttemptTwice` decorator.
- **D-M2: optional dependencies used as silent fallbacks (about 20 constructors).** Examples: `CaptureService.php:50` and `RefundService.php:55` (`?TransactionAuditRecorder = null` means no audit row), `Controller/Admin/OrderActionDispatcher.php:47` (`release()` returns false when the dispatcher is null, `:81`), `Service/Return/MollieReturnResolver.php:45-46`, `Service/CheckoutSessionStarter.php:52-53`, `PaymentHandler/MolliePaymentHandler.php:73-74`, `EventSystem/Handler/Mollie*RequestHandler.php` (feedback/translator), `Admin/MolliePaymentPanelProvider.php:61`. Behaviour quietly degrades when wiring is wrong. Fix: make them required and let the container fail at boot.
- **D-M3: LSP/ISP gap with payment-base.** `CaptureService` and `RefundService` deliberately bypass `AbstractPaymentCaptureService`/`AbstractPaymentRefundService` (docblocks `CaptureService.php:29-31`, `RefundService.php:31-40`), and Stripe does the same. The base abstraction does not fit any provider. Fix: redesign the base around a provider "bound + execute" port, or delete it.
- **D-M4: `MolliePaymentHandler` (379 lines)** keeps mutable per-process state in a DI singleton (`$iframeFallbackLogged`, `:61,248`) and builds `new OxidLanguageTranslator()` in its constructor (a hidden dependency).

### DRY / agnostic-gnostic boundary
Sibling comparison after normalising provider names (lines that still differ):

| Mollie class | Duplicate in | Differing lines | Should live in |
|---|---|---|---|
| `Service/OxidStockRestorationService.php` (129) | stripe (122) | 23 | payment-base (it owns `StockRestorationServiceInterface` but ships no implementation; both modules alias the same interface, `services.yaml:817`, so the last loaded wins) |
| `Controller/Webhook/WebhookHttpsGuard`, `…PayloadSizeGuard`, `…IpAllowlistGuard`, `…RateLimitGuard`, `…GuardChain/Result/Request` | stripe + paypal | 9–69 each | payment-base `Webhook/Guard` (payment-base already has `Validation/Guard/PayloadSizeGuard`) |
| `Service/ContractTokenService.php` | stripe | same design, different salt and secret source | payment-base (see S-M3) |
| `Service/ContractRefundRecorder.php` | stripe | 71 | payment-base |
| `Service/UserDataValidator.php` (121) | stripe (121) | 64 | payment-base validation |
| `Controller/Admin/OrderActionDispatcher.php` | stripe, paypal | 142–176 | generic dispatcher in payment-base, with providers adding only extras |
| `Service/ModuleConfigurationService.php`, `Service/PaymentMethodInstaller.php` | stripe/paypal | large | base class in payment-base for settings and payment-method installation |

- **DRY-M1 (Medium):** the paid ladder duplicates `ContractCommitService` (see D-H1).
- **DRY-M2 (Medium):** the stock-restore-on-any-refund bug (S-H2) exists in two copies (Mollie and Stripe) because the code was copied rather than shared.
- No agnostic→gnostic leaks found in Mollie (it only consumes payment-base). The boundary problems are the missing shared pieces above.

### TDD
- **T-H1 (High):** the most important money rules are untested or pinned wrong:
  - no test that an idempotency key reaches the Mollie client (S-H1);
  - `RefundServiceTest` only has a full-refund stock case (`tests/Unit/Service/RefundServiceTest.php:243-262`), so S-H2 is pinned in place;
  - no test for `paid` on a terminal contract (S-H3);
  - the expiry test pins the S-M1 behaviour.
- **T-M1:** `RefundBound` (`src/Mollie/Service/RefundBound.php`), the single refund ceiling shared by the admin panel and `RefundService`, has **no dedicated unit test**. It is only covered indirectly through `AdminActionBoundsTest`/`MolliePanelViewDataBuilderTest`.
- **T-M2:** no tests for chargeback delta and reversal or for refund-after-chargeback event identity (S-M6). Nothing covers the admin-refund vs webhook race (S-M4).
- Positive: webhook processor, handlers, retry semantics, event identity, the amount DTO (35 refs) and the status mapper (15 refs) are well covered.

### Clean Code
- **C-M1:** comment archaeology. 186 Sprint/Story/MOL/OPC/review tags in `src/`, and docblocks narrate history instead of intent (e.g. `WebhookController.php:37-60`, `WebhookContractFulfillmentHandler.php:54-58`). Move the history to the dev log and keep the "why".
- **C-M2:** `services.yaml` is 921 lines with many hand-wired services that autowiring would cover, and it carries comments explaining cross-module container collisions (an architectural smell: shared interfaces bound by several modules).
- **C-M3:** stale or misleading docs: `Controller/Admin/OrderActionDispatcher.php:36` ("No admin UI is wired to this yet (Sprint 7)"); `WebhookContractFulfillmentHandler.php:206-210` ("payment already taken", false for manual capture).
- Service locator use (`ContainerFactory`) in `WebhookController::init` (`:77-102`), `Core/ViewConfig.php:78,207,216` and `MollieOrderController::resolveService` (`:313`) is OXID-imposed and acceptable. `oxNew` in `OxidStockRestorationService::loadOrder` is properly seamed.

## 3. Low importance
- `MollieAmountDto::formatValue()` always uses 2 decimals (`src/Mollie/Adapter/Dto/MollieAmountDto.php:53`). Fine while everything is EUR-only (`Core/MollieDefinitions.php:173`); wrong for JPY/ISK if the currency list grows.
- `shippingAddress: $billingAddress` (`src/Mollie/Service/CheckoutPaymentService.php:79`): Klarna and other pay-later methods always get the billing address as the delivery address.
- The webhook payload guard allows 1 MiB for a ~20-byte body (`Controller/Webhook/WebhookPayloadSizeGuard.php:19`); 4 KiB is plenty.
- The `missing_id` (400) and `processor_unavailable` (500) paths are not written to the webhook file log (`WebhookController.php:136-142`).
- `webhook_processed` logs only `action`+`success`, with no payment id, event id or skip reason (`WebhookController.php:151-154`), so the file log cannot be correlated with anything (see §4.2).
- A not-found or unreachable payment is reported as `signature_invalid`/400 (`MollieWebhookProcessor.php:75-81`, `WebhookController.php:288`). A Mollie outage is logged as forgery, and the response body works as an "id exists on this account" oracle.
- Contract-not-found handling is inconsistent: the refunded and chargeback handlers skip at once with no wait window (`PaymentRefundedHandler.php:53`, `ChargebackCreatedHandler.php:51`).
- API keys are masked with `type="password"`, but the value is still in the admin DOM (`views/twig/extensions/themes/admin_twig/module_config.html.twig:38`).
- `adminMolliePaymentRelease` is guarded by the CAPTURE right (`GraphQL/Admin/Controller/MollieAdminPaymentController.php:37`). Consider a dedicated release/cancel right.
- Money is handled as floats (`Service/MoneyComparison.php` EPSILON, `CaptureService.php:122-126`). Use integer cents or a payment-base Money value object.
- `remoteIp` is logged on every failed verification (`payment-base/src/Webhook/AbstractWebhookProcessor.php:61-64`). IPs are personal data; keep retention short.
- `MollieLatePaymentRefunder::giveBack()` fetches again a payment the processor has just fetched (`Service/MollieLatePaymentRefunder.php:94`).
- Mollie API exception messages are logged verbatim (`Service/CheckoutUserDataGate.php:56`, `Service/TransactionHistoryService.php:115`) and may echo submitted field values (needs confirmation).
- `'paid'`/`'authorized'` string constants are redeclared in `PaymentPaidHandler.php:21` and `MollieLatePaymentRefunder.php:38-39` instead of using `MollieStatusMapper::STATUS_*`.
- The IP allowlist guard is IPv4-only (`ip2long`, `WebhookIpAllowlistGuard.php:52-60`) and unwired. Delete it or make it support IPv6.

## 4. Server logs (pay1)

### 4.1 oxideshop.log
Source: pay1 `log/oxideshop.log`, 2026-10-12 → 2026-10-30 15:36.

| Level | Count | First–Last seen | Still recent? | Message (normalised) | Likely cause / code location | Sev |
|---|---|---|---|---|---|---|
| ERROR (TypeError → 500) | 8 | 10-15 15:19 – 15:22 | no | `MolliePaymentHandler::__construct(): Argument #1 ($checkoutStarter) must be CheckoutSessionStarter, EventDispatcher given` | Stale container after deploy (`src/Mollie/PaymentHandler/MolliePaymentHandler.php:69`) | Med (gone) |
| ERROR | 1 | 10-22 14:38 | no | `MollieCheckoutSessionHandler: create payment failed` → Mollie 422 `amount is higher than the maximum` (EUR 171,581.40) | Expected provider rejection, contract failed correctly; logged at `src/Mollie/EventSystem/Handler/MollieCheckoutSessionHandler.php:210-211` with `$e->getMessage()` which **contains the full request body** | Low (func) / **Med (security)** |
| ERROR | 1 | 10-16 20:46 | no | `Unknown argument "cardToken" on field "mollieCheckoutStart"` | Storefront ahead of deployed Mollie GraphQL schema | Low |
| ERROR | 1 | 10-16 20:26 | no | `Return URL rejected (origin_not_allowed) …/payment-mollie` | payment-base allow-list | Low |
| WARNING | 12 (3 × 4 contracts) | 10-12 | no | `[WebhookContractFulfillmentHandler] transition not applied` (transitionToPending / fulfillCondition / commitToOrder; state=committed) | Webhook arrives after the redirect return already committed — idempotent no-op (`src/Mollie/Webhook/Handler/WebhookContractFulfillmentHandler.php:421`) | Low (could be INFO) |

**Interpretation:** no functional Mollie bug in the window; the only 500s were a 3-minute stale-container window on 10-15. **Security:** the Mollie SDK exception message includes `Request body: {…}` and the module logs it verbatim — this leaks the `redirectUrl` with `contract_token=…` (return-URL capability token), the `cardToken` (`tkn_…`), metadata and the webhook URL. Log HTTP status + field only. Mollie `services.yaml:253` naming `$earlyOrders` ahead of payment-base contributed to the whole-shop fatal at 10-30 02:45 (see payment-base report).

**Verdict: Low functional risk; Medium security** (request-body logging).

### 4.2 log/mollie webhook logs (your analysis)

**Format.** Every one of the 482 lines has the same shape:
```
[2026-10-30 01:08:18] WEBHOOK webhook_processed {"action":"contract_expired","success":true}
```
The only fields are `action` and `success`. There is **no payment id, contract id, event id, skip reason or IP** (`WebhookController.php:151-154`).

**Secrets / PII:** none. No API keys, emails, names, addresses or IPs appear in any file, so there is no security finding for these logs. The downside is that they are nearly useless for diagnosis (Low finding above).

**Volumes** (41 files, 2026-08-17 … 2026-10-30, with gaps on days without deliveries):

| Month | Days | Total | fulfilled | authorized | expired | failed | canceled | skipped |
|---|---|---|---|---|---|---|---|---|
| 2026-10 | 22 | 168 | 30 | 27 | 16 | 16 | 3 | 76 |
| 2026-09 | 16 | 305 | 67 | 2 | 76 | 19 | 12 | 129 |
| 2026-08 | 3 | 9 | 5 | 0 | 4 | 0 | 0 | 0 |
| **All** | 41 | **482** | 102 | 29 | 96 | 35 | 15 | **205 (42 %)** |

**October, most recent first:**
- **10-30:** 12 × `contract_expired` between 01:08 and 03:33, plus 1 at 12:35, and 3 × `skipped` at 02:27, 02:30 and 02:34, interleaved with the expiries. These look like a batch of abandoned or uncaptured payments expiring together. The interleaved skips match expiries on contracts that are already COMMITTED (NoOp, `WebhookContractFulfillmentHandler.php:211-213`) or terminal. If they are expired **authorizations** from the 10-16 manual-capture runs (14 days earlier), they are instances of S-M1: the order stays committed and is never marked as unpaid (needs confirmation against `oe_payments_webhooklogs` by time).
- **10-23 02:00:28–31:** 15 × `skipped` in 3 s. Also **10-24 02:00** (6), **10-19 02:00** (1) and **10-10 02:00** (1). This is a recurring 02:00 pattern, consistent with a nightly cron such as `oe:payments:not_finished:cleanup`, which cancels contracts and orders locally. The resulting Mollie `canceled`/`expired` deliveries then hit already-terminal contracts and are answered NoOp, or they are payments older than the 10-minute wait window → "Contract not found" (needs confirmation of the pay1 crontab). Harmless, but the log cannot tell these cases apart.
- **10-16 / 10-17:** 15 + 6 `contract_authorized`, 9 + 4 `contract_failed`, 10 + 3 `contract_fulfilled`. Manual-capture and failure testing (MOL-31/32 period). The failure ratio is high but expected for test cards.
- **10-19 to 10-29:** very low traffic (0–3 per day), mostly `skipped`.
- **10-01 to 10-15:** steady low volume; `skipped` peaks on 10-06 (14) and 10-13 (10).

**September bursts:** 09-22 15:31–15:33 had 54 `skipped` in about 2 minutes (61 that day); 09-18 13:45–13:49 had 28 `skipped`; 09-29 had the busiest mixed day (19 fulfilled / 16 expired / 13 failed / 10 canceled). These bursts are batch re-deliveries or mass cancellations, almost certainly during testing.

**Errors / failures:** `success:false` appears **zero times**. There is also no `webhook_rejected` line, so in 2.5 months the file log shows:
- no failed verification (`signature_invalid`, i.e. **no unknown or forged well-formed payment id ever reached the processor**);
- no `contract_not_found` 5xx (no race with the commit);
- no `fulfillment_failed`/`processing_failed`;
- no 429 from the rate limiter and no TLS rejection.

Malformed ids (`missing_id`) and `processor_unavailable` are **not logged at all**, so enumeration attempts with bad shapes would be invisible.

**Failed claims / retries:** none visible. With no 5xx answered there was nothing for Mollie to retry, and duplicate deliveries show up only as anonymous `skipped`.

**Status transitions that look wrong:**
1. The high share of `skipped` (42 %) cannot be split into duplicate delivery, unhandled `open`/`pending`, NoOp on a terminal or committed contract, or "contract not found after the wait window". Bugs such as S-M1 (expired authorization on a committed contract) and S-M7 (refund not recorded but reported as recorded) would look exactly like this.
2. `contract_expired` (96) is close to `contract_fulfilled` (102). That is plausible on a test shop full of abandoned redirects, but on a live shop it would point to a checkout drop-off problem.
3. No `refund_recorded` or `chargeback_recorded` action appears at all, although admin refunds were tested in this period (MOL-30, 10-09). Either the refund webhooks were answered as `skipped` (delta ≤ 0, because the admin path recorded first, which is expected), or S-M7 applied. The log cannot tell which.

**Recommendation for the log:** add `paymentId`, `eventId`, `eventType`, `contractId`, the skip/failure reason and the HTTP status to `webhook_processed`, and log `missing_id`/`processor_unavailable` as `webhook_rejected`. None of these are secrets. Mollie payment ids are not PII.

## 5. Recommended next steps (ordered)
1. **S-H1:** send idempotency keys to Mollie (`setIdempotencyKey`) using a per-action nonce from the admin form and GraphQL. Add an adapter test.
2. **S-H2:** stock restore only on full refund, and respect `blUseStock`. Fix Stripe as well, then move one `OxidStockRestorationService` into payment-base.
3. **S-H3:** handle `paid`/`authorized` on terminal contracts with an idempotent refund or release, record it, alert, and answer 200. Test first.
4. **S-M1:** handle an expired authorization on a committed but uncaptured contract (flag or cancel the order, notify the merchant). Rewrite the pinning test.
5. **S-M5:** in payment-base, catch `Throwable` and allow stale CLAIMED rows to be claimed again.
6. **D-H1/DRY-M1:** one commit path. Require `ContractCommitService`, delete the legacy branch, and route `paid` through it.
7. **S-M4/S-M7/S-M6:** make refund and chargeback bookkeeping delta-based and idempotent, and stop reporting `refund_recorded` when nothing was recorded.
8. **Webhook log enrichment** (§4.2), then check the 10-30 02:27–02:34 skips and the 02:00 bursts against `oe_payments_webhooklogs`.
9. **S-M2/S-M3:** make the rate-limit key proxy-aware, and move a shared token service with expiry into payment-base.
10. Move the triplicated webhook guards, contract refund recorder and user-data validator into payment-base. Make the optional constructor dependencies required (D-M2).
