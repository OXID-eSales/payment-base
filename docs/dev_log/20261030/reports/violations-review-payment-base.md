# payment-base — violations review (2026-10-30)

Scope: `oxid-esales/payment-base`, branch `b-7.4.x` @ `6fc1f0a`. Read-only review of `src/` (521 PHP files), `views/`, `services.yaml`, `metadata.php`, tests (319 files). Siblings `../stripe`, `../mollie-payment`, `../paypal` checked only to confirm reachability and duplication. No code changed. High/Medium findings verified against the code; "(needs confirmation)" = depends on runtime wiring or behaviour outside this module.

## Summary

| Severity | Security | Design / quality | Total |
|---|---|---|---|
| High | 3 | 3 | 6 |
| Medium | 7 | 9 | 16 |
| Low | — | — | 15 |

**Live production issue on pay1 (see §4):** payment-base migrations `Version20261026100000`, `…28…`, `…29…` have **not run** on pay1 (verified 2026-10-30). STRP-176 deferred order mails are therefore off: every order since 10-28 15:19 sends its confirmation mail before payment, and each failure dumps the full customer mail (PII) into `oxideshop.log`.

Top 3 code risks:
1. **Lost webhook events (S1)** — the webhook claim catches only `\Exception`; an `\Error`, fatal or timeout leaves the event row `claimed` forever → every PSP retry answered "already processed" → a paid payment may never commit its contract.
2. **Amount/currency checked on only one of two commit paths (S2, S3)** — `ContractCommitService` compares PSP amount/currency with the contract; the return leg (`CheckoutReturnResponder`) dispatches `PaymentAuthorizedEvent` directly and checks neither. And `ContractService` still falls back to `'EUR'` and `0.0` totals when building the contract amount.
3. **payment-base doesn't own capture/refund rules (D1, D4, S5)** — its capture/refund templates and fat `PaymentAdapterInterface` are used by no provider; Stripe, Mollie and PayPal each reimplement bounds (remaining refundable, currency, state) with different sources of truth and no per-contract lock.

## 1. Security — High / Medium

| ID | Sev | Location | Issue | Impact | Fix |
|---|---|---|---|---|---|
| S1 | **High** | `src/Webhook/AbstractWebhookProcessor.php:69,80` (`use Exception;`, verified); `src/Repository/DoctrineWebhookLogRepository.php:73-104` | `claimEvent()` inserts a `claimed` row; only `catch (Exception)` resets to `failed`; `reclaimFailed()` re-claims only `failed`. A `TypeError`/`Error`, fatal, timeout or lost DB connection in `processEvent()` leaves the row `claimed` with no TTL. Stripe/Mollie processors don't catch `Throwable` either. | Every later delivery is "skipped" (2xx) → PSP stops retrying → contract never commits, paid order stays NOT_FINISHED until manual reconciliation. | Catch `Throwable` → `failed`; let `reclaimFailed()` take `claimed` rows older than N minutes (`OXRECEIVEDAT < now - X`). |
| S2 | **High** | `src/Controller/CheckoutReturnResponder.php:104,112-123` vs `src/Service/Commit/ContractCommitService.php:93-112` | Return leg dispatches `PaymentAuthorizedEvent` with `$resolution->amount/currency` and never compares with the contract; only `ContractCommitService` does. Mollie/PayPal resolvers echo the contract's own amount (proves nothing); Stripe passes the PSP amount, compared by nobody. | A PSP amount/currency differing from the contract (basket changed after intent, provider bug, partial payment method) commits and fulfils on the return leg. Two commit paths with two guard sets — breaks "one straightforward path". | Route the return leg through `ContractCommitService::commit()`, or make `amountMatches()` a shared guard both legs must pass; resolvers must report PSP figures. |
| S3 | **High** | `src/Service/ContractService.php:342-376` (`$currency = 'EUR'` l.347, totals `0.0` l.344-346, verified) | Contract amount — the figure every provider charges — built by `method_exists` duck typing with silent fallbacks to `'EUR'` / `0.0`. Exactly the defect class `src/Adapter/ShopCurrency.php:15-35` was written to remove ("CHF 100 charged as EUR 100"); this fifth site was missed. | Unavailable currency object (misconfigured `aCurrencies`, currency deleted mid-session) → contract stamped EUR → Mollie/PayPal (using `$contract->getCurrency()`) charge the wrong currency; basket without `getPrice()` → 0.00 contract. | Use `ShopCurrency::codeOf()` (throws); type the parameter as OXID `Basket`; refuse `totalGross <= 0` unless the payment type explicitly allows it. |
| S4 | Medium | `src/GraphQL/Admin/Service/OperationIdempotency.php:50-61`; `src/Repository/DoctrineIdempotencyRepository.php:33-47,51-65` | Check-then-act claim: `findByKey()` sees an expired/released record (id = `md5(key)`), `save()` does SELECT COUNT then UPDATE → two concurrent re-claims both get FRESH; UNIQUE key only protects the first INSERT. `findByKey()` returns `null` on DB exception (= "fresh"). A different `idempotencyKey` per call skips serialization entirely (`client:<key>`). | After a release, or after the 2-min in-flight window lapses while the first call is still running, two admin capture/refund calls on the same order reach the PSP — double capture/refund, mitigated only provider-side. | Single guarded UPDATE (`… WHERE OXKEY=? AND (OXEXPIRES < now OR OXSTATUS='released')`) + affected-rows check, or a fresh row per attempt; `findByKey()` rethrows; per-order mutex (`GET_LOCK('pb_order_'.$orderId)`) around money moves regardless of client key. |
| S5 | Medium | `src/Service/AbstractPaymentRefundService.php:61-65,151-199` | Read → compute `availableForRefund` → PSP call → log, no lock; float compare without epsilon; contract `refundedAmount` not updated (transaction table = second source of truth); no idempotency key to the adapter. Currently used by no provider (D1). | Two concurrent refunds both pass the bound; `OXVERSION` optimistic check fires only after both PSP calls. | Delete (D1) or add per-contract lock, `Money::atMost()`, idempotency key, single bookkeeping via `PaymentContract::addRefundedAmount()`. |
| S6 | Medium | `src/Contract/CaptureRefundTracker.php:65-85` | `addRefundedAmount()` accepts totals beyond `capturedAmount`; `setCapturedAmount()` accepts more than authorized `getAmount()`. The aggregate doesn't enforce its own invariants. | Over-refund/over-capture bookkeeping persisted; admin bounds, `isFullyRefunded()`, order state disagree with the PSP. | Enforce `refunded + amount <= captured`, `captured <= authorized` (Money tolerance) in the tracker; throw `DomainException`. |
| S7 | Medium | `src/Mcp/Acp/AbstractAcpCheckoutService.php:128-230` | `get/update/cancel/completeCheckout` load any contract by id; no check that the calling agent created it (`acp_agent_id`) or that it's an `acp` contract; `updateCheckout` writes arbitrary `acp_*` metadata. Subclassed by all 3 providers. No MCP transport wired today (needs confirmation for future wiring). | IDOR once ACP is enabled: any authenticated agent can read/cancel/complete storefront customers' contracts. | Ownership guard `getMetadata('acp_agent_id') === $agentContext->getAgentId()`; allow-list updatable keys. |
| S8 | Medium | `src/Mcp/Auth/JwtTokenValidator.php:19-60` | Decodes JWT, checks `iss`/`aud`/`exp`, **never verifies the signature** (no JWKS/key, `alg` ignored). No tests. Not wired. | Anyone wiring it as MCP validator accepts forged tokens. | Delete (unused) or verify with a JWKS-backed library + pinned alg. |
| S9 | Medium | `src/EventSystem/Handler/RefundIntentHandler.php:177`, `:139-160` | `array_merge($base, $intent->getCorrelationContext())` lets intent keys override `providerName`, `contractId`, `orderId`, `amount`. Committed/fulfilled branch doesn't bound the refund by remaining refundable. (needs confirmation: depends on who emits `RefundIntentEvent`, e.g. opalreturns.) | A colliding correlation key retargets the provider action (wrong contract/amount) while logs show the base values. | `array_merge($correlation, $base)` or namespace correlation keys; bound by `getRemainingRefundableAmount()` once, here. |
| S10 | Medium | `src/Admin/PaymentAdminController.php:179-205` vs `src/GraphQL/Admin/Service/AdminPaymentOperations.php:46-128` | Same money-moving actions on two paths: GraphQL has `#[Right(ADMIN_PAYMENT_CAPTURE/REFUND)]`, step-up MFA, audit, idempotency; classic `dispatchAction()` has only stoken + admin login. | Admin without payment rights / MFA can capture/refund via the classic tab; double submit not deduped. | Route `dispatchAction()` through `AdminPaymentOperationsInterface` — one path. |

## 2. Design & code quality — High / Medium

### SOLID

| ID | Sev | Location | Issue | Impact | Fix |
|---|---|---|---|---|---|
| D1 | **High** | `src/Adapter/PaymentAdapterInterface.php:51` (18 methods); `src/Service/AbstractPaymentRefundService.php:34`; `AbstractPaymentCaptureService.php:33`; `PaymentCaptureService.php` | ISP: fat adapter only Stripe implements; capture/refund templates depend on it, so Mollie and PayPal explicitly refused them (`mollie-payment/services.yaml:555-566`, `mollie-payment/src/Mollie/Service/RefundService.php:31-40`); Stripe and PayPal have their own services → templates dead, orchestration reimplemented per provider. | Capture/refund invariants in 3 modules with 3 sources of truth (contract `refundedAmount` in PayPal, Mollie API in Mollie, PSP in Stripe); S5/S6-type fixes needed three times. | Agnostic `RefundPolicy` / `CapturePolicy` (bounds, currency, state, lock, bookkeeping) over narrow `RefundGateway`/`CaptureGateway` ports; delete fat interface + dead templates. |
| D2 | Medium | `src/EventSystem/Handler/EarlyOrderCreationHandler.php:70`; `ContractConditionResolverHandler.php:39` | LSP/DIP: `instanceof PaymentContract` (concrete) — other `PaymentContractInterface` implementations silently skipped, no order created. | Silent no-op on substitution. | Use the interface; add `transitionToNotFinished` etc. to it. |
| D3 | Medium | `src/Adapter/OxidShopOrderService.php` (724 lines) | SRP: order creation, rewrite-in-transaction, session challenge, mail deferral policy, send-now fallback, NOT_FINISHED cancellation, state/error mapping, metadata stub. | High change coupling; every checkout story touches it (the STRP-176 fallback in §4 lives here, l.329). | Split: order writer, rewriter, mail deferral, not-finished canceller. |

### DRY / agnosticism

| ID | Sev | Location | Issue | Impact | Fix |
|---|---|---|---|---|---|
| D4 | **High** | `stripe/src/Stripe/Service/RefundService.php`, `paypal/src/PayPal/Service/RefundService.php:20-133`, `mollie-payment/src/Mollie/Service/RefundService.php` (+ 3 `CaptureService`s) | Refund/capture bound logic written three times with different rules (PayPal falls back to `'EUR'`, Stripe has no remaining-bound in the service). Belongs in payment-base (D1). | Behaviour drift; security fixes in three places. | As D1: one agnostic policy; providers supply only the PSP call. |
| D5 | Medium | `src/GraphQL/Admin/Subscriber/PaymentOwnershipSubscriber.php:32-36` | Hard-coded provider payment ids/names (`oe_payments_stripe_wallet => stripe`, `oe_payments_paypal`, `oe_payments_mollie`) in the agnostic core. | 4th provider or renamed payment id needs a payment-base release. | Tagged `PaymentOwnershipProviderInterface` registered by each provider (or `PaymentHandlerRegistry::forPaymentMethod`). |
| D6 | Medium | `src/ClientConfig/ClientConfigGuard.php:27-29,84-93`; `src/Webhook/WebhookPayloadSanitizer.php:26-43`; `src/Webhook/WebhookProcessor.php:57` | Provider secret/payload shapes in core: Stripe `sk_/rk_/whsec_`, Mollie key regex, Stripe PII keys (`billing_details`, `receipt_email`, `last4`), `extractPaymentIntentId()`. Sanitizer misses PayPal/Mollie PII (`payer`, `email_address`, `consumerAccount`/IBAN). | Coverage gaps for other providers; each new provider changes base. | Providers contribute deny patterns / PII keys via tagged service; base keeps generic rules only. |
| D7 | Medium | Dedupe ×3: `AbstractWebhookProcessor` + `DoctrineWebhookLogRepository::claimEvent` (Stripe, Mollie); `src/Webhook/WebhookProcessor.php` + `WebhookIdempotencyChecker.php` (dead, TOCTOU, saves the event twice); `paypal/src/PayPal/Service/WebhookLogService.php:36-52` (find-then-save) | Three idempotency mechanisms for one concern. | PayPal lacks atomic claim, failed-retry re-claim and the S1 fix (cf. PayPal SEC-2). | One `WebhookClaim` service for all three; delete `WebhookProcessor`, `WebhookIdempotencyChecker`, `WebhookEventDispatcher`, `WebhookRequestParser` + tests. |

### TDD

| ID | Sev | Location | Issue | Impact | Fix |
|---|---|---|---|---|---|
| D8 | Medium | `src/Mcp/Auth/JwtTokenValidator.php`, `src/Service/AbstractPaymentRefundService.php` | No tests for a security validator or for refund-bound arithmetic. | S5/S8 unnoticed. | Delete (preferred, both unused) or tests first (forged signature rejected; concurrent/over-refund rejected). |
| D9 | Medium | `tests/Unit/Webhook/AbstractWebhookProcessorTest.php` (8 tests); `CheckoutReturnResponder` | No test for `\Error` during `processEvent()` or re-claiming a stale `claimed` row; no amount/currency mismatch test on the return leg. | S1/S2 untested. | Red tests: `TypeError` in `processEvent` → re-claimable; mismatched return amount → no commit. |
| D10 | Medium | `tests/Unit/Webhook/{WebhookProcessor,WebhookIdempotencyChecker,WebhookEventDispatcher,WebhookRequestParser}Test.php`; `tests/Unit/Mcp/**` | Tests keep dead code alive (none wired in any `services.yaml`, checked across all 3 providers). | Maintenance cost, false coverage. | Delete with the dead code (C1). |

### Clean Code

| ID | Sev | Location | Issue | Impact | Fix |
|---|---|---|---|---|---|
| C1 | Medium | `src/Webhook/{WebhookProcessor,WebhookIdempotencyChecker,WebhookEventDispatcher*,WebhookRequestParser*}.php`; `src/Mcp/{McpServer,Auth/*,Handler/*,Notification/*,Ucp/*}` (~40 of 50 MCP files unreferenced outside `src/Mcp`); `AbstractWebhookProcessor::isAlreadyProcessed/logWebhookReceived` (:100-128); `OxidShopOrderService::storeOrderMetadata` (:700-715, commented-out "Option 1/2") | Dead code, some insecure (S8; SSRF-capable `AgentCallbackRegistry` / `AgentNotificationService` posting to arbitrary callback URLs). | Attack surface waiting to be wired; misleading reference code. | Remove, or park on a feature branch until the MCP transport exists. |
| C2 | Medium | `src/EventSystem/Handler/RefundIntentHandler.php:61-77` + `services.yaml:637-643` (`@?`); `src/Adapter/OxidShopOrderService.php:88-92` (`?? new SessionBasketProvider()`, optional guard/mail store/sender); `src/Controller/CheckoutReturnResponder.php:60-63`; `EarlyOrderCreationHandler.php:125` (`'unknown_payment'`) | Fallbacks violating "one straightforward path": `RefundIntentHandler` returns **silently** without repository/broker; orders can be written with payment id `unknown_payment`. | Misconfiguration → silent no-ops / bad data instead of failed boot. | Required deps (`@`), throw on missing payment id, test seams via constructor injection only. |
| C3 | Medium | `src/Service/ContractService.php:51` (`shopId: 1`); `src/Repository/DoctrineContractOrderMailStore.php:71` → `src/Adapter/OxidShopMailSender.php:26-81` | Every contract stamped shop 1; deferred order mail uses the contract shop's SMTP/sender. (needs confirmation on EE subshops.) | EE subshop order mails sent with shop 1's SMTP/sender; per-shop contract queries impossible. | Shop id from an injected shop context (`Registry::getConfig()->getShopId()`). |
| C4 | Medium | `src/Adapter/OxidShopOrderService.php:329`; logging of DBAL exception (§4) | STRP-176 mail stash failure is caught and the mails are **sent immediately** (fallback), and the raw DBAL message — including bound params = the full rendered customer mail — is logged verbatim. | Silent functional regression on schema drift (live on pay1) + ~105k lines of customer PII in `oxideshop.log`. | Fail loudly (the column must exist — migration is a deploy precondition); log contract/order id + SQLSTATE only. |

## 3. Low importance

- `src/Repository/DoctrineWebhookLogRepository.php:73` — UNIQUE(`OXEVENTID`) global, not per provider; use `(OXPROVIDER, OXEVENTID)`.
- `src/Webhook/AbstractWebhookProcessor.php:86` — `$e->getMessage()` returned to the PSP in `WebhookResult::failure`; return a code.
- `src/Controller/ValidationApiController.php:79` — `init()` swallows `Throwable`; with no guards loaded validation would run unguarded (today ends in 500 since loader is null).
- `src/Checkout/Headless/HeadlessCheckoutService.php:80,213-231` — `paymentIdFor()` runs before the basket ownership check and names the payment method in the error.
- `src/Adapter/OxidGuestUserService.php:36-56` — anonymous token knowing a guest e-mail overwrites that guest's name/address (core parity, needs confirmation).
- `views/twig/extensions/themes/default/page/checkout/thankyou.html.twig:24` — `notice|raw` safe only while providers queue lang strings; document that PSP text must be escaped.
- `src/EventSystem/Handler/RefundIntentHandler.php:192-195` — `round($amount, 2)` breaks 0/3-decimal currencies; use `MinorUnitConverter`.
- `ContractCommitService.php:111` (`<=`) vs `InFlightCheckoutAttemptResolver.php:106` (`>=`) — duplicated `AMOUNT_TOLERANCE` with opposite boundaries; use `Money::equals()`.
- Money as `float` everywhere (contract, tracker, events) although a `Money` VO exists.
- `src/Repository/DoctrineContractRepository.php:573` — reflection hydration of private props; use `PaymentContract::reconstitute()`.
- `src/Contract/PaymentContract.php` (747 lines) — state machine + serialization + metadata bag.
- `phpstan.neon:16-37` — `level: max` weakened by blanket `ignoreErrors`; 4 classes `@SuppressWarnings(PHPMD)` for all rules.
- `HeadlessCheckoutService.php:392-407`, `OxidShopOrderService.php` (15 `Registry::`/`oxNew`) — static shop access outside `Oxid*` adapters.
- `src/Repository/DoctrineIdempotencyRepository.php:47` — `catch (Exception $e) { throw $e; }` no-op.
- Docs drift: CLAUDE.md says orders exist only on fulfilment, but `EarlyOrderCreationHandler` writes a NOT_FINISHED order at DRAFT; stale `payment-base/.claude/worktrees/STRP-176` (git-excluded) exists.
- `FraudCheckHandler.php:81` — 4 warnings "no contract in context" on Mollie webhook replays (§4).

## 4. Server logs (pay1)

Source: `/var/www/oxid/oxid75/source/source/log/oxideshop.log` on pay1, 2026-10-12 → 2026-10-30 15:36 (112,015 lines / 4,114 entries: 1,250 ERROR, 1,792 WARNING, 1,066 INFO, 0 NOTICE, 0 CRITICAL; 6 PHP fatals `[uncaught error] [type E_ERROR]` = whole-shop 500, one of them ours/payment-base). ~105k of the lines are 46 payment-base `[STRP-176]` entries, each dumping a whole rendered order mail.

| Level | Count | First–Last seen | Still recent? | Message (normalised) | Likely cause / code location | Sev |
|---|---|---|---|---|---|---|
| ERROR | 46 | 10-28 15:19 – 10-30 12:18 | **yes** | `[STRP-176] order mails could not be kept, order mails sent before the payment` → `SQLSTATE[42S22] Unknown column 'OXORDERMAILSTATE'` | Migration `migration/data/Version20261028100000.php` **not run on pay1**; stash fails in `src/Repository/DoctrineContractOrderMailStore.php:45`, catch in `src/Adapter/OxidShopOrderService.php:329` falls back to sending immediately | **High** |
| ERROR | 2 | 10-29 09:52 – 10-30 01:19 | yes | `[OrderConfirmationMailHandler] order mails not sent` → `Unknown column 'c.OXORDERMAILSTATE'` | Same missing column; claim at `DoctrineContractOrderMailStore.php:57`, logged at `src/EventSystem/Handler/OrderConfirmationMailHandler.php:68` | High |
| ERROR + E_ERROR fatal | 1 + 1 | 10-30 02:45 | yes | `Invalid service EarlyOrderCreationHandler: __construct() has no argument named "$earlyOrders"` | Deploy version skew: stripe `services.yaml:471` / mollie `services.yaml:253` named `$earlyOrders` before the payment-base that has it (`src/EventSystem/Handler/EarlyOrderCreationHandler.php:45`) was deployed → container compile failed → **whole shop 500** | High (deploy order) |
| ERROR | 16 | 10-19 19:11 – 10-20 18:00 | no | `Cannot query field "paymentClientConfig" on type "Query"` | GraphQL client ahead of deployed payment-base (`src/GraphQL/Controller/PaymentClientConfig.php:44`) | Low |
| ERROR | 9 | 10-22 13:58 – 13:59 | no | `non-existent service …\GraphQL\Relation\OrderRelationService` | Private service fetched by graphqlite type mapper (`services.yaml:402/409`); fixed by private def + public FQCN alias (10-27) | Med (gone) |
| ERROR | 5 (4 Stripe, 1 Mollie) | 10-16 20:25 – 20:26 | no | `Return URL rejected (origin_not_allowed): https://pay1…:8443/checkout/return/payment-*` | Headless storefront port 8443 not on allow-list (`src/Checkout/ReturnUrl/ReturnUrlRejectedException.php:41`) — config, works as designed | Low |
| WARNING | 4 | 10-12 | no | `FraudCheckHandler got an event with no contract in context; skipping` | Mollie webhook replay dispatches `PaymentAuthorizedEvent` without contract (`src/EventSystem/Handler/FraudCheckHandler.php:81`) | Low |

**Interpretation**
- The STRP-176 deferred-mail feature is effectively off on pay1: since 10-28 15:19 every order sent its confirmation mail *before* payment — exactly the regression STRP-176 was built to prevent. The catch-and-send-now fallback also contradicts the "no fallbacks" rule. **Verified live on pay1 (2026-10-30, read-only query):** no `OXORDERMAIL*` column exists, and `oxmigrations_payment_base` ends at `Version20260924120000` — so `Version20261026100000`, `Version20261028100000` and `Version20261029100000` (all present in `vendor/oxid-esales/payment-base/migration/data`) have **not** run. The issue is still live. Note: the root `post-update-cmd` hook (`vendor/bin/oe-payment-base-migrate`) evidently did not fire in the pay1 deploy path.
- **Security / PII:** the DBAL exception text is logged verbatim, including bound params = the full rendered customer mail (recipient e-mail, name, address, order lines) — ~105k lines of PII. Log contract/order id + SQLSTATE only.
- The 10-30 02:45 fatal is a deploy-ordering issue (consumer `services.yaml` ahead of payment-base): deploy payment-base first and/or tighten the consumers' version constraint.

**Constellation context (OPC / other, not payment-base code):** OPC-289 `login-clear … STATE SURVIVED THE CLEAR` diagnostic tracer logged at ERROR (~700, 136 in the last 3 days, still active); OPC 500s on 10-30 00:22–03:00 during a half-deployed OPC (`Unknown "isSubscribedToNewsletter" function` in `order-extras.html.twig:229`, class-not-found for OPC `ViewConfig`/`StartController`/…); `BasketSummary` JSON endpoint → `processTemplateRenderError(): $templateName null` (16×); OPC logs full delivery address + phone (`DeliveryAddressService: No user in session`, 15×) and guest e-mails at INFO (incl. a real external tester address) — Med (PII). Core fatals during module (de)activation (`ModuleSettingServiceInterface`, `ActiveModulesDataProviderBridge`). No API keys (`sk_`/`pk_`/`rk_`/`whsec_`/Mollie `live_`/`test_`) found anywhere in the log.

**Verdict: High (live).** Run `vendor/bin/oe-payment-base-migrate` on pay1 now; stop logging raw DBAL messages carrying mail bodies; enforce payment-base-first deploy order + container cache rebuild before traffic.

## 5. Recommended next steps (ordered)

1. **pay1 now (§4)** — run `vendor/bin/oe-payment-base-migrate` on pay1 (3 pending migrations), confirm `OXORDERMAILSTATE` exists, and find out why the post-update hook didn't run in the deploy. Make the deploy order payment-base → providers → container cache rebuild → traffic.
2. **C4** — stop logging the raw DBAL message (customer mail PII); fail loudly instead of send-now fallback. Consider purging the PII lines from pay1's `oxideshop.log`.
3. **S1** — catch `Throwable` in `AbstractWebhookProcessor::process()`; reclaim stale `claimed` rows. Red test first (D9).
4. **S2 + S3** — one commit path: return leg through `ContractCommitService` (amount/currency guard); `extractTotals()` uses `ShopCurrency`, refuses 0.00 / unknown-currency contracts.
5. **S4 + S10** — atomic admin claim (guarded UPDATE, per-order lock); classic `dispatchAction()` through `AdminPaymentOperations` (rights, step-up, audit, idempotency).
6. **D1 / D4 / S5 / S6** — invariants into `CaptureRefundTracker`; agnostic `RefundPolicy`/`CapturePolicy` with narrow gateway ports; migrate Stripe, Mollie, PayPal; delete `PaymentAdapterInterface` + `AbstractPayment*Service`.
7. **C1 / S7 / S8 / D7 / D10** — delete dead webhook pipeline, unwired MCP auth/server/notification code + tests; ACP ownership check before any MCP transport is wired.
8. **C2 / S9** — required deps (`@` not `@?`), fail at boot; fix correlation merge order.
9. **D5 / D6** — provider ids, secret shapes, PII keys via provider-registered tagged services.
10. **C3** — shop id from shop context. Low items opportunistically.
