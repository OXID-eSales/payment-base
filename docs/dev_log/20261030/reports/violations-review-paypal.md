# paypal — violations review (2026-10-30)

Scope: `source/extensions/paypal`, branch `b-7.4.x` @ `8538946` (~203 PHP files in `src/`, 161 test files). Read-only review, no changes. Paths relative to the module root. Siblings read for DRY checks: `../payment-base`, `../stripe`, `../mollie-payment`.

## Summary

| Severity | Security | Design / quality | Total |
|---|---|---|---|
| High | 3 | 3 | 6 |
| Medium | 6 | 11 | 17 |
| Low | — | — | 15 |

Top 3 risks:
1. **Return-leg IDOR (SEC-1)** — `paypalCheckoutSuccess` trusts `contract_id` from the URL: no contract token, no owner/session check. The guard written for this (`ReturnSessionSecurityService`) is dead code whose tests pass against an unused class. Stripe and Mollie both have this check.
2. **Webhook claims never released (SEC-2)** — handler throws → controller answers 500 so PayPal retries → idempotency row stays `pending` → every retry gets `200 duplicate` → event lost forever. payment-base already fixed this for Stripe/Mollie (MOL-17 `claimEvent` + `reclaimFailed`); PayPal never migrated.
3. **Refunds counted twice (SEC-3)** — admin refund adds to `refundedAmount`, then the `PAYMENT.CAPTURE.REFUNDED` webhook adds it again (no refund-id dedupe). Refunded total too high, refundable remainder too low / zero.

## 1. Security — High / Medium

| ID | Sev | Location | Issue | Impact | Fix |
|---|---|---|---|---|---|
| SEC-1 | High | `src/PayPal/Controller/PayPalOrderController.php:113-142` (`paypalCheckoutSuccess`), `:269-280`; dead guard `src/PayPal/Service/ReturnSessionSecurityService.php:32-56` | Contract loaded from request `contract_id` and passed straight to `dispatchCheckoutReturn()`. No contract token (Mollie: `HandlesMollieCheckoutReturn.php:43-50`), no owner check (Stripe: `StripeOrderController.php:365-373`). `ReturnSessionSecurityService` (session id + PayPal `token` check) is never called; only its constant is used (`PayPalContractCreationHandler:48`). `contract_id` travels in the query string (`CheckoutSessionService.php:100`) → browser history, proxy/access logs, Referer. | Anyone holding a contract id (128-bit random, so it must leak first) can drive the return leg of another shopper's contract: settle/capture, commit the order, and get `sess_challenge` set in **their own** session → victim's thank-you page (name, address, items). The provider is not checked either, so Stripe/Mollie contract ids are accepted (PSP-SWITCH family). | One shared check before dispatch: contract user = session user, + `initiating_session_id` or contract token, + `token` = `providerOrderId`, + provider `paypal`. Move the owner check into payment-base (Stripe's `CheckoutReturnInputsResolver::checkOwnership`) for all providers. Add a controller test. |
| SEC-2 | High | `src/PayPal/Controller/Webhook/WebhookController.php:59-80`; `src/PayPal/Service/WebhookLogService.php:75-102` | Event claimed (`pending` row in `oe_payments_idempotency`) before the handler runs. If the handler throws, the controller answers 500 to make PayPal retry (`CheckoutOrderApprovedHandler.php:84` and `PaymentCaptureCompletedHandler.php:208` rely on that), but the claim is never released → `claim()` false on every retry → `200 duplicate` (`:60-63`). | One transient failure (DB hiccup, stale save, PayPal 5xx during settle, refused commit) permanently loses CHECKOUT.ORDER.APPROVED / CAPTURE.COMPLETED / CAPTURE.REFUNDED. Orders stay NOT_FINISHED/unpaid, refunds never booked. | Adopt payment-base `WebhookLogRepositoryInterface::claimEvent()` / mark-failed / `reclaimFailed()` (as Stripe and Mollie do), or at minimum mark `failed` in the catch and let `claim()` re-take failed rows. Test: throwing handler → redelivery → processed. |
| SEC-3 | High | `src/PayPal/WebhookHandler/PaymentCaptureRefundedHandler.php:30-46`; `src/PayPal/Service/RefundService.php:59-63` | Admin refund books `addRefundedAmount($effectiveAmount)`; PayPal then sends `PAYMENT.CAPTURE.REFUNDED` for the same refund and the handler adds `resource.amount.value` again. No refund id stored/compared (capture handler has `alreadyRecorded()`, refund handler has nothing). | Every shop-initiated refund double-counted on the contract: refundable remainder too low (admin can't refund the rest), wrong status/OXPAID derivation, ledger ≠ PayPal. (Resolver lookup via `supplementary_data.related_ids.order_id` is what tests assume — confirm against a live refund payload.) | Store processed refund ids (contract or transaction table) and skip already-recorded ids. Better: one payment-base "apply provider refund(id, amount)" that dedupes by id for all providers. Test: admin refund + its webhook → one booking. |
| SEC-4 | Medium | `src/PayPal/Service/CheckoutSessionService.php:62`; `src/PayPal/Service/ApprovedOrderSettler.php:26,35` | `PayPal-Request-Id` = `"{contractId}:checkout"` / `":auth"` / `":capture"` — constant across attempts, but since OPC-175 a contract is **continued** after cancel (`payment-base ContractService::createContract → continueAttempt`). PayPal returns the cached first response for a repeated request id within its idempotency window. | Cancel → change basket → checkout again: `createOrder` can return the **old** PayPal order (old amount/items); a later capture can return a cached decline. Nothing reconciles amounts (SEC-5). (Needs confirmation in PayPal sandbox.) | Make the key attempt-specific: PayPal order id for auth/capture; basket hash or attempt counter for createOrder. |
| SEC-5 | Medium | `src/PayPal/Service/Return/PayPalReturnResolver.php:88-120`; `src/PayPal/WebhookHandler/CheckoutOrderApprovedHandler.php:91-96`, `PaymentCaptureCompletedHandler.php:201` | Commit uses `contract->getAmount()`; the amount/currency PayPal actually authorized or captured (`completedCaptureTotal()`, auth amount) is never compared. `?? contract amount` and `authorizationId ?? orderId` (`ApprovedOrderSettler.php:30`) are silent fallbacks. | Under-paid / mismatched PayPal order (SEC-4, manipulated order, currency mismatch) is committed and marked paid. | Compare PayPal amount + currency to the contract before `readyToCommit`/`authorized`; `failed('amount_mismatch')` on difference; remove the fallbacks. |
| SEC-6 | Medium | `src/PayPal/Controller/PayPalOrderController.php:64-111,164-199`; `views/twig/widget/checkout/paypal_footer.html.twig:50-60` | `execute`, `createOrderJson`, `paypalCheckoutStart` never call `checkSessionChallenge()` (core `OrderController::execute` does, l.208). Redirect path skips `TermsGate` (`:168` checks SmartButtons only); `paypalCheckoutStart` has no terms check. Fallback form hard-codes `ord_agb=1`, `ord_custinfo=1` and both product agreements. | Cross-site POSTs create NOT_FINISHED orders / contracts / PayPal orders for a logged-in shopper; AGB and digital-goods consent recorded without the shopper agreeing — compliance gap (cf. MOL-11 / MOL-9). | stoken check on all three entry points; `TermsGate` in every UX mode; render the agreement checkboxes instead of hidden `=1`. |
| SEC-7 | Medium | `src/PayPal/Controller/PayPalOrderController.php:180,341-343` | Smart Buttons `onError()` returns raw `Throwable::getMessage()` as JSON; JS shows it (`resources/js/controllers/paypal_button_controller.js:250,282`). `PayPalAdapterException` wraps SDK messages (response bodies, debug ids, URLs). OPC path already sanitises ("138d", `PayPalPaymentHandler.php:141-157`). | Provider/internal error details shown to shoppers. | Fixed message + code to the browser, details to debug log (like `PayPalPaymentHandler::failed()`). |
| SEC-8 | Medium | `src/PayPal/Controller/Webhook/WebhookRateLimitGuard.php:106-130`; `WebhookIpAllowlistGuard.php:27-63`; `WebhookController.php:153-156` | Rate-limit buckets live in an instance property → reset every request → never limits. IP allow-list passes all private/loopback addresses → behind any proxy/tunnel everyone passes. `X-Forwarded-Proto` always trusted. Each unauthenticated delivery that passes triggers an OAuth token call + verify-webhook-signature call (`PayPalWebhookSignatureVerifier.php:270-275`, no token cache). | No flood protection; outbound PayPal API amplification (can hit merchant rate limits). Hard-coded CIDRs may drop genuine deliveries on directly exposed hosts (PayPal publishes no stable ranges — needs confirmation). | Use payment-base `DoctrineRateLimitStore` / `ApcuRateLimiter`; make proxy trust and allow-list configuration; cache the access token until expiry. |
| SEC-9 | Medium | `src/PayPal/WebhookHandler/PaymentCaptureDeniedHandler.php:38-44`; `PaymentCaptureCompletedHandler.php:218-227`; `CheckoutOrderApprovedHandler.php:62-69` | `catch (Throwable)` wraps the transition **and** `contracts->save()` (Denied), or runs every ladder step and ignores the outcome. | Failed save / refused transition (e.g. DENIED on a committed order) swallowed, event marked processed, 200 returned — order stays "paid" while PayPal denied the capture. | Catch only the specific transition exception; let persistence errors propagate (with SEC-2 fixed); log/alert on refused transitions. |

## 2. Design & code quality — High / Medium

### SOLID

| ID | Sev | Location | Issue | Fix |
|---|---|---|---|---|
| Q-1 | Medium | `src/PayPal/Controller/PayPalOrderController.php` (381 lines, PHPMD WeightedMethodCount baselined) | 5 endpoints (redirect start, JSON create, form start, success, cancel) + context building, locale, currency, amount formatting, JSON output and a service locator in one class. No unit test; security checks get lost (SEC-1, SEC-6). | Split into start / return / cancel controllers; context via `CheckoutEventContextBuilder` (D-4). |
| Q-2 | Medium | `CheckoutOrderApprovedHandler.php:42-43,58-71`, `PaymentCaptureCompletedHandler.php:91,130`, `PayPalReturnResolver.php:40-42`, `CaptureService`/`RefundService` | 18 nullable `private readonly ?…` collaborators; `null` silently selects a legacy "ladder" path (`?? new ApprovedOrderSettler`). Two runtime paths per handler — violates "one straightforward path"; DI mistakes go unnoticed. | Make them required; delete legacy branches. |
| Q-3 | Medium | `WebhookContractFulfillmentHandler.php`; `services.yaml:552-559` | Dispatch by event-type string inside each handler, first match wins (`return true`) → one handler per event type; Vault handlers (`VaultPaymentToken*Handler`) aren't in the list at all. | Use payment-base `WebhookEventDispatcher`. |

### DRY / agnostic-gnostic boundary

| ID | Sev | Location | Issue | Fix |
|---|---|---|---|---|
| D-1 | High | `src/PayPal/Controller/Webhook/*` (9 files) vs `../stripe/src/Stripe/Controller/Webhook/*`, `../mollie-payment/src/Mollie/Controller/Webhook/*` | Webhook guard chain + controller copied three times (`WebhookGuardResult` byte-identical to Mollie's modulo renames). PayPal ignores payment-base `Webhook/AbstractWebhookProcessor`, `WebhookIdempotencyChecker`, `WebhookPayloadSanitizer`, `DoctrineWebhookLogRepository::claimEvent/reclaimFailed` and keeps its own `WebhookLogService`. payment-base fixes (MOL-17) never reach PayPal → SEC-2. | Move guard chain + controller skeleton into payment-base; providers keep only `verify()` + handler map; PayPal switches to `claimEvent`. |
| D-2 | High | Stripe `CheckoutReturnInputsResolver::checkOwnership`, Mollie `tokenIsValid`, PayPal — none | Return-leg ownership/token check is provider-agnostic but implemented per provider; PayPal forgot (SEC-1). | payment-base `CheckoutReturnResponder::respond()` (or a guard in front) enforces owner + token for every provider. |
| D-3 | Medium | `src/PayPal/EventSystem/Handler/PayPalOrderCreationHandler.php` (183 lines, CC/NPath baselined) | Re-implements payment-base `EarlyOrderCreationHandler` with a 3-way OR "is PayPal" heuristic (`:80-88`). | Extend/configure the payment-base handler. |
| D-4 | Medium | `PayPalOrderController.php:82-97, 216-233, 304-314`; `PaymentHandler/CheckoutEventContextBuilder.php:91-103` | Checkout context (currency, amount, locale, shop-url fallback) built three times; amount formatting twice. | One builder used by both controllers. |
| D-5 | Medium | PayPal SDK, `Adapter/CurlPayPalRestTransport.php`, `Service/CurlConnectHttpInvoker.php` | Three HTTP clients, each with its own token handling, none cached. | One transport, cached token. |
| D-6 | Medium | `src/PayPal/Adapter/OxidShopOrderService.php` (244 lines), `services.yaml:163,166` | Registered but interface binding removed 2026-09-01; payment-base's `OxidShopOrderService` does the work; only its own test uses it. | Delete it and its test. |

### TDD

| ID | Sev | Location | Issue | Fix |
|---|---|---|---|---|
| T-1 | High | `tests/Unit/Security/F20_SessionFixationTest.php`, `tests/Unit/Service/ReturnSessionSecurityServiceTest.php` | Security tests cover `ReturnSessionSecurityService`, which production never calls; no `PayPalOrderControllerTest` → false assurance on SEC-1. | Controller tests: foreign user, foreign session, `token` mismatch, foreign provider → rejected. |
| T-2 | Medium | `tests/Unit/Controller/WebhookControllerVerificationTest.php` (3 tests) | Missing: throw → redelivery; concurrent claim (unique-key violation from `claim()` at `WebhookController.php:60`, outside the try); fulfillment service missing. Would have caught SEC-2. | Add these cases. |
| T-3 | Medium | refund / capture handlers | No cross-path money idempotency tests: admin refund + REFUNDED webhook (SEC-3); partial capture A, B, then webhook A (`alreadyRecorded()` compares only the **last** `METADATA_CAPTURE_ID` → A double-counted); retry after cancel with same request ids (SEC-4). | Add them. |

### Clean Code

| ID | Sev | Location | Issue | Fix |
|---|---|---|---|---|
| C-1 | Medium | `PaymentCaptureCompletedHandler.php:218-227`, `CheckoutOrderApprovedHandler.php:62-69`, `CaptureService.php:126-130` | State "ladders" call `$contract->$method()` by string inside `catch (Throwable) {}` — untyped, hides failures (SEC-9). | Explicit transitions guarded by `canTransitionTo…()`. |
| C-2 | Medium | `WebhookController.php:174-208`, `PayPalOrderController.php:373-380`, `PayPalConnect.php:158-200`, `PaymentController.php:46-58` | `ContainerFacade::get()` inside `catch (Throwable) { return null; }`, then a silent alternative path (no idempotency log → no dedupe; no fulfillment → `200 ignored`; no dispatcher → `parent::execute()`). | Fail loudly (500) — a DI error is a deploy bug. |
| C-3 | Medium | `PayPalDefinitions::METADATA_CAPTURE_ID`; `CaptureService.php:122`, `PaymentCaptureCompletedHandler.php:157`, `PayPalReturnResolver.php:150`, `RefundHelper.php:29-35` | One capture id per contract, overwritten by each capture; refunds always target the last capture while the bound is the contract total → PayPal refuses refunds above the last capture; capture dedupe breaks (T-3). | Keep a capture list (id, amount); allocate refunds across it. |
| C-4 | Medium | `Adapter/PayPalStatusMapper.php`, `Service/RequestLogService.php` (bound `services.yaml:127-133`, never injected), `Service/ReturnSessionSecurityService.php`, `Adapter/OxidShopOrderService.php`, `PayPalReferralService::fetchSellerCredentials/disconnect` (stubs) | Dead code in services. | Wire where needed or delete. |

## 3. Low importance

- `PayPalOrderController.php:195-198` — falls through to `onError('no_approval_url')` after `redirect()` (exits); unreachable but misleading.
- `PayPalOrderController.php:258`, `PayPalPaymentHandler.php:208` — writing `$_POST['sDeliveryAddressMD5']` from the server copy defeats core's delivery-address check.
- `PayPalOrderController.php:154-156,241` — `new FrameBreakout()` / `new TermsGate()` instead of injection.
- `Controller/Admin/PayPalConnect.php:117-119` — referral failure "degraded fallback" to bare signup URL without nonce (no-fallback rule).
- `PayPalConnect.php:63-86` — uncaught `authAfterWebLogin` exceptions → HTML error page to admin JS.
- `views/twig/extensions/themes/admin_twig/module_config.html.twig:46,56` — `live_url|raw` / `sandbox_url|raw` in `href`; safe today, use `|e('html_attr')`.
- Approval-URL redirect (`PayPalOrderController.php:106,196`) has no host allow-list (unlike `PayerActionUrlValidator`).
- `WebhookIpAllowlistGuard.php:72-78` — IPv4 only; public IPv6 senders always rejected.
- `PayPalReturnResolver.php:160-164`, `CheckoutOrderApprovedHandler.php:118-122`, `PaymentCaptureCompletedHandler.php:202` — `'EUR'` default currency.
- `PayPalWebhookSignatureVerifier.php:270` — new OAuth token per webhook delivery.
- `WebhookContractResolver.php:44-47` — empty "future hook" `if (custom_id)` block.
- No handlers for `PAYMENT.CAPTURE.REVERSED`, `PAYMENT.CAPTURE.PENDING`, `CUSTOMER.DISPUTE.*` — chargebacks never reach the contract.
- `services.yaml:552-559` — Vault token handlers not registered → `ignored_unknown_event`.
- `PayPalAdapter.php:244,270,281` — missing PayPal amount mapped to `0.00 EUR` instead of an error.
- `CaptureService.php:27-30` — stale docblock ("written by the handler in sprint 15").

## 4. Server logs (pay1)

### 4.1 oxideshop.log
Source: pay1 `log/oxideshop.log`, 2026-10-12 → 2026-10-30 15:36.

**Nothing from PayPal.** No ERROR, WARNING or fatal originates from the PayPal module in the window. PayPal appears only in OPC INFO handler listings (10-12) and in one `paymentClientConfig` GraphQL error that belongs to payment-base (GraphQL client ahead of deployed payment-base, 10-19/20).

Caveat: "clean log" here also means low observability — check §4.2 regarding PayPal not writing its own webhook log, and confirm PayPal had real traffic on pay1 in this window before reading the silence as health.

**Verdict: clean.**

### 4.2 provider log

There is no `log/paypal/` directory on pay1 — PayPal writes no provider log. Webhook/API diagnostics go only to the shared `oxideshop.log` (`Registry::getLogger()` in `WebhookController.php:44,55,70`), and the request/response logger `RequestLogService` is never wired (C-4).

**Recommendation: yes, log webhooks like Stripe and Mollie.** Stripe → `log/stripe/stripe_webhooks_<date>.log` (`../stripe/src/Stripe/Service/Factory/WebhookFileLoggerFactory.php:36`), Mollie → `log/mollie/mollie_webhooks_<date>.log` (`../mollie-payment/src/Mollie/Service/Factory/MollieWebhookFileLoggerFactory.php:36`), both on payment-base `AbstractFileLoggerFactory`. Add a `PayPalWebhookFileLoggerFactory` → `log/paypal/paypal_webhooks_<date>.log`, logging per delivery: event id, type, resolved contract id, claim result, handler outcome, HTTP status; payload through payment-base `WebhookPayloadSanitizer` (no payer PII, no tokens). Do it as part of D-1, not as another PayPal-only copy. Without it, SEC-2-style lost events can't be diagnosed on the server.

## 5. Recommended next steps (ordered)

1. **SEC-1 / D-2 / T-1** — owner + session + `token` check in `paypalCheckoutSuccess` (ideally one payment-base guard for all providers); delete or wire `ReturnSessionSecurityService`; controller tests.
2. **SEC-2 / D-1 / T-2** — move PayPal webhooks onto payment-base `claimEvent` / `reclaimFailed` + throw-then-redeliver test; then move the guard chain into payment-base and delete the three copies.
3. **SEC-3 / C-3 / T-3** — dedupe refunds and captures by PayPal id (refund-id ledger, capture list); tests running admin path + webhook path for the same money movement.
4. **SEC-4 / SEC-5** — attempt-specific `PayPal-Request-Id`; reconcile PayPal amount + currency with the contract before commit (verify request-id behaviour in sandbox first).
5. **SEC-6 / SEC-7** — stoken + `TermsGate` on all front entry points; no hard-coded agreements; sanitised Smart Buttons errors.
6. **SEC-8 / SEC-9 / C-1 / C-2** — real shared rate limiter, configurable proxy/IP trust, cached OAuth token; remove catch-all ladders and silent `null` service lookups.
7. **Q-1 / Q-2 / D-3..D-6 / C-4** — split `PayPalOrderController`, required collaborators, reuse payment-base `EarlyOrderCreationHandler`, one context builder, one HTTP transport, delete dead classes.
8. Add `log/paypal/paypal_webhooks_<date>.log` (§4.2) together with step 2.
