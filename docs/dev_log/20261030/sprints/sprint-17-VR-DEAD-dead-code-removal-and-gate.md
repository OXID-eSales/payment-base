# Sprint 17 — VR-DEAD: dead-code check in each module — remove what never runs, then keep it out with a CI gate

**Date:** 2026-10-30 (check run 2026-11-03) · **Epic:** VR · **Ticket:** tbd
**Branches:** `b-7.4.x-VR-17-dead-code` in payment-base, stripe, mollie-payment, paypal.
**Status:** PLANNED · **Priority:** P1 — runs right after sprint 16, before 18–25, so that the later sprints do not
fix, test or move code that never runs (e.g. pb S7/S8 MCP findings disappear with the code).
**Requirements:** [`_engeneering_requirements.md`](_engeneering_requirements.md) — "Dead-code gate".
**Check reports (evidence, file/line level):**
[`../reports/deadcode-check-payment-base.md`](../reports/deadcode-check-payment-base.md) ·
[`../reports/deadcode-check-stripe.md`](../reports/deadcode-check-stripe.md) ·
[`../reports/deadcode-check-mollie.md`](../reports/deadcode-check-mollie.md) ·
[`../reports/deadcode-check-paypal.md`](../reports/deadcode-check-paypal.md)

## How the check was done

1. Class-level scan of every `src/**/*.php` in the four modules: a class is *live* when its FQCN (or, inside its own
   module, its short name) occurs in production text outside its own file — `src/` of all constellation modules
   (incl. one-page-checkout, opalreturns, opalsubscription, oxid-admin-graphql), `services*.yaml`, `metadata.php`,
   `menu.xml`, `views/`, JS, `migration/` — with PHP comments stripped. Tests do **not** count. Result: 195 candidates
   (pb 104, stripe 39, mollie 24, paypal 28).
2. Every candidate triaged by hand-reading the wiring: **DEAD** (no production caller), **TEST-ONLY** (only tests keep
   it), **UNWIRED** (registered in DI, never injected/tagged/dispatched), **LIVE** (wiring named).
3. Below class level: `fnc` endpoints without template/JS/redirect caller, unused public methods in the largest
   classes, settings never read, translation keys never used, templates/JS never loaded, services.yaml ids without a
   class, commented-out code, `@deprecated` without callers, nullable "legacy ladder" branches that the container
   never selects.
4. Code state: payment-base `d062955` (5 SEAL-1 commits after the review's `6fc1f0a`), stripe `b4ae8b0`,
   mollie-payment `5f9fc0e`, paypal `8538946`. Read-only, nothing executed.

## Result per module

| Module | Dead now (src) | Tests that go with it | Unwired / needs decision | Review claims refuted |
|---|---|---|---|---|
| **payment-base** | 76 files ≈ 3,750 lines (Mcp 42 files/2,015; legacy webhook pipeline 11/602; condition-type registry 6/129; events+handlers 11/430; capture/refund/void templates 6/571) + ≈ 500 lines below class | 22 files ≈ 2,860 lines deleted; 2 edited | 14 files ≈ 1,000 lines (ACP chain, `WebhookLogService`, `VoidAuthorizationRequestedEvent`, `InMemoryRateLimitStore` used by mollie tests) — needs consumer PRs | `PaymentAdapterInterface` is **live** (Stripe implements it) — only the abstract templates are dead |
| **stripe** | 18 files ≈ 1,560 lines (Payment Element path PHP + frontend; `ChargeDisputeCreatedWebhookHandler`; `StripeWebhookEvent`; `DumpExtension` + 2 env classes; stray JS/CSS/twig) + ≈ 600 lines partial (yaml incl. a 42-line commented block, twig blocks, getters) + 147 translation keys × 2 | 8 files ≈ 1,200 lines | `StripeAcpCheckoutService`, `StripeRadarFraudCheckService` (Radar never runs — bug, moved to sprint 23 T6), `blStripeRemoveBy*` settings | `RequestLogService`, `ReturnSessionSecurityService` are **live** in Stripe |
| **mollie-payment** | almost clean: 3 dead members, 1 translation key, 2 test-only methods | 4 assertions | `WebhookIpAllowlistGuard` (registered, not in the chain); `OxidSessionAdapter` copy + 4 yaml copies of payment-base bindings; legacy commit ladder (moves with sprint 20 R6) | — |
| **paypal** | ≈ 709 lines DEAD (incl. the whole `PaymentController` extension chain: core posts `fnc=validatepayment`, nothing posts `fnc=execute` — 8 files) + ≈ 748 lines UNWIRED to delete (`OxidShopOrderService`, `RequestLogService`, `OxpaidReconciliationService`, vault cluster) + ≈ 300 below class | 15 test files | `ReturnSessionSecurityService` **kept** (wired by sprint 20 R1); vault + `blPayPalRemoveBy*` settings need a product decision | "three HTTP clients" are all live (duplication → sprint 25, not dead code) |

**Total removable once all batches land: ≈ 8,000 src lines and ≈ 5,500 test lines.**

**Findings that are bugs, not dead code** (moved, not deleted):
- stripe Radar check never runs → sprint 23 T6.
- paypal `PaymentCaptureCompletedHandler` fallback still runs for committed contracts and double-adds the captured
  amount on redelivery → sprint 22 A7.
- `ShopAdapterInterface` bound by all three providers, last `services.yaml` wins → sprint 25 D0.
- **opalsubscription** listens (Symfony listeners) for `PaymentCaptured`, `PaymentFailed`, `ContractCancelled`,
  `ContractExpired`, which payment-base never instantiates/dispatches through its own dispatcher ⇒ subscription
  renewal orders, failure handling and termination mirroring never run. **Owner decision + opalsubscription ticket**
  (out of this sprint; do **not** delete those payment-base events until decided).

## Stories

Each batch = one commit, Unit + Integration + phpstan + phpcs green after it; batches touching `metadata.php` include a
module deactivate/activate; batches touching checkout UI include a Playwright smoke. File lists per batch are in the
check reports.

### payment-base (merges first; CHANGELOG "Removed", branch-alias bump per the release rule)

| # | Batch | Notes |
|---|---|---|
| DC-PB1 | `src/Mcp` transport: 42 files + `src/Mcp/docs` + 8 tests | keeps `Http/ApcuRateLimiter` + `RateLimiterInterface` (Stripe uses them) and the ACP chain (DC-PB7). Closes pb S8 (JWT) and the SSRF-capable notification code (C1) |
| DC-PB2 | legacy webhook pipeline: `WebhookProcessor`, `WebhookIdempotencyChecker`, `WebhookEventDispatcher*`, `WebhookRequestParser*` + interfaces + `WebhookReceivedEvent`, `AbstractWebhookProcessor::isAlreadyProcessed/logWebhookReceived`, `WebhookPayloadSanitizer` (dead once those go) + 5 tests | run stripe + mollie webhook suites against the branch. Sprint 19 W6 becomes a no-op; sprint 18 L2 re-introduces a sanitizer only if the delivery record needs one |
| DC-PB3 | condition-type registry (6 files) + `ContractCondition` registry branch, `setConditionTypeRegistry`, the throwing `agentIdentityVerified`/`agentConsentConfirmed` factories | — |
| DC-PB4 | events/handlers never dispatched or never registered: `ContractConditionFulfilled`, `OrderCompleted`, `ContractFailed`, `PaymentInitiated` (+ interfaces); `Generic…`, `ContractCleanup…`, `PaymentAuthorization…` handlers (registrations already commented out) | rewrite `ContractCreationHandlerTest`, swap sample event in `EventListenerProviderTest`. **Keep** the four events opalsubscription listens for until the owner decides |
| DC-PB5 | capture/refund templates: `AbstractPaymentRefundService`, `AbstractPaymentCaptureService`, `PaymentCaptureService`, `Capture/RefundFailedException`, `VoidResponse` + 3 tests | closes pb S5/D8; `PaymentAdapterInterface` stays until sprint 22 A4 |
| DC-PB6 | below class: `OxidShopOrderService::storeOrderMetadata` (+ OPC/Stripe copies via their PRs), 4 `@deprecated` `FraudCheckResponse` getters, `OpenAttemptFinderInterface` alias, ≈ 25 unused repository/service methods, 8 lang keys; `OXSTATEREASON`/`OXTERMS` writes, then a separate column-drop migration | migration runs through `oe-payment-base-migrate` (sprint 16 O3 gate) |
| DC-PB7 | cross-repo, consumers first: (a) stripe/mollie/paypal ACP services + yaml → pb ACP chain (unless the owner keeps MCP — then sprint 24 H8 instead); (b) stripe `WebhookDbLogServiceFactory` → pb `WebhookLogService`(+I) and 3 repository methods; (c) stripe/paypal translator tests → `VoidAuthorizationRequestedEvent`; (d) `InMemoryRateLimitStore` → test-support namespace; (e) `RateLimitOverrideInterface` | each consumer PR merges before the pb commit that removes the class |

### stripe

| # | Batch | Notes |
|---|---|---|
| DC-S1 | zero-risk tidy: duplicate `paid` check (`CheckoutSessionCompletedWebhookHandler.php:93`), 42-line commented yaml block, `assets/js/controllers/*`, `stripe-admin.min.js(.map)` + esbuild admin target, `payment_stripe_form.html.twig`, `stripe_payment_element.css` | — |
| DC-S2 | debug twig: `DumpExtension` + `DevelopmentEnvironmentChecker{,Interface}` + yaml + 2 tests | — |
| DC-S3 | webhook leftovers: `ChargeDisputeCreatedWebhookHandler` (not in catalog) **only if** the owner does not choose to flag disputes (sprint 23 T3) — otherwise it is wired there; `StripeWebhookEvent` + `StripeAdapter::parseWebhook` | decision recorded |
| DC-S4 | Payment Element path PHP: `StripePaymentStatusHandler`, `StripePaymentReturnHandler`, 2 events, `executeStripePayment`/`stripeReturn`/`processContextResults`, 3 `ControllerRequestHelper` getters, yaml, tests | **this is sprint 20 R2** (S-1 security fix) — done there with the exploit regression test; DC-S4 only verifies nothing is left |
| DC-S5 | Payment Element path frontend: `select_payment` override + vault block in `payment.html.twig` (gated on non-existent `oscstripe_card`), `stripe_card`/`select_payment` twig, `stripe-order` Stimulus controller + registrations, element config includes; rebuild bundles | Playwright checkout smoke (hosted, embedded, OPC) |
| DC-S6 | orphaned `Adapter/OxidShopOrderService` (414 lines) + yaml + test (repoint `StaleOrderCleanupIntegrationTest` at payment-base's class); `Core/ShopCurrency` → payment-base's | — |
| DC-S7 | dead small methods (`is3DSecureEnabled` reading undeclared `blStripe3DSecure`, `isTransactionLoggingEnabled`, `stripeIsTestMode`, `stripeHasApiKeys`, `isStripeDebugEnabled`, `supportsCurrency/Country`, `testConnection`, `isValidSecretKey`, VO getters) | — |
| DC-S8 | translations: 94 frontend + 53 admin keys per language (incl. 42 `*_CHARACTER` keys), de+en in lockstep | — |
| DC-S9 | decisions (separate tickets): ACP service, `blStripeRemoveBy*` settings + oxid-fe-admin GraphQL fields, `blStripeLogTransactionInfo` legacy seed | Radar → sprint 23 T6 |

### mollie-payment

| # | Batch | Notes |
|---|---|---|
| DC-M1 | `getRefund()` (interface + `MollieAdapter` + `LazyMollieAdapter` + test stub), `MollieCaptureRequestEvent::isFullCapture()`, lang key `MOLLIE_VALIDATION_INVALID_USER_DATA` | — |
| DC-M2 | test-only: `CheckoutStartOutcome::isUnavailable()`, `MollieConfigSnapshot::keySet()` | — |
| DC-M3 | `WebhookIpAllowlistGuard` + yaml + 3 tests | superseded by payment-base's guard in sprint 19 W5 |
| DC-M4 | `Adapter/OxidSessionAdapter` copy + yaml + integration test; yaml copies of payment-base bindings (`EventListenerProviderInterface`, `EventDispatcherInterface`, `TransactionRepositoryInterface`, `ContractServiceInterface`) | re-activate with Mollie alone, then with Stripe + PayPal (container collision check, see sprint 25 D0) |
| DC-M5 | legacy commit ladder (`advanceToAuthorized`, optional `ContractCommitService`) | **this is sprint 20 R6**; DC-M5 only verifies |

### paypal

| # | Batch | Notes |
|---|---|---|
| DC-P1 | zero-reference: `PayPalCancelUrlBuildEvent`, `PayPalSuccessUrlBuildEvent`, `PayPalVaultAdapter`(+I) + yaml comment, `Service/Result/CheckoutReturnResult` (GraphQL uses payment-base's), empty block `WebhookContractResolver.php:44-47`, 14 lang entries | — |
| DC-P2 | dead getters/stubs: `PayPalConnect` template getters + `buildReferralLink`, `ModuleConfiguration::getPayPalModuleId/isPayPalModule`, `ViewConfig::isPayPalSandbox/getPayPalMode`, footer getters, `PayPalReferralService::fetchSellerCredentials/disconnect`, `ModuleConfigurationService::getPartnerId/isConfigured` | — |
| DC-P3 | `PayPalStatusMapper` + 2 tests; `Adapter/OxidShopOrderService` (245 lines) + test + yaml `:163-168` | closes paypal D-6 |
| DC-P4 | `RequestLogService`(+I) + `NullLogger` def + 4 tests; `OxpaidReconciliationService`(+I) + test | sprint 18 L5 adds the real webhook file log instead |
| DC-P5 | legacy `PaymentController` chain: `metadata.php` extend, controller + `_parent` alias, 3 events, 3 handlers, `PayerActionUrlValidator` + tests | re-activate; Playwright classic + OPC PayPal checkout. Sprint 24 H2 then reuses a host validator from payment-base instead |
| DC-P6 | vault cluster (2 handlers, `PayPalCustomerService`(+I), `Result/PayPalCustomer`, `isVaultingEnabled`); optional second commit: vault settings + admin GraphQL fields | **owner decision** (product); default proposal: delete |
| DC-P7 | after sprint 20 R1: `PayPalOrderController::execute()` + redirect tail + `UxModeResolver`; empty `menu.xml` | — |
| DC-P8 | nullable "ladders" (~15, `services.yaml:632` `@?`) | merged into sprint 25 D3; `WebhookController` in sprint 19 W3 |

### DC-5 — the gate (all four modules)

| Step | Red (first) | Green | Proof |
|---|---|---|---|
| a. Tool | CI job `dead-code` fails on a deliberately added unused class in a test branch | add `shipmonk/dead-code-detector` (PHPStan extension; verify compatibility with each module's PHPStan version first) to `require-dev`; entry points: `metadata.php` controllers/extends, tagged services, console commands, GraphQL controllers, twig extensions; tests excluded as usage sources | CI log of the red test branch, then green on the cleaned module |
| b. Baseline | — | baseline only for items explicitly kept by an owner decision (opalsubscription events, kept ACP, `ReturnSessionSecurityService` until R1 lands); each baseline line carries the ticket id | baseline file reviewed in the PR |
| c. Non-PHP | `composer dead-assets` script fails on an unused lang key / template | small script (kept in each repo's `bin/`): lang keys never referenced in `src/`, `views/`, JS; twig files never included/rendered; JS/CSS not in any build entry | CI green |
| d. Rule | — | `_engeneering_requirements.md` "Dead-code gate" + PR template checkbox "new code has a production caller" | — |

## Definition of done

All batches except owner-decision items merged (payment-base first, consumer PRs before cross-repo removals);
`dead-code` CI job green in all four repos with a baseline that contains only owner-kept items; numbers before/after
(src lines, test lines, PHPMD/phpstan baseline sizes, consumer test counts) in `done/`.

## Owner decisions needed before the matching batch

1. Keep or delete MCP/ACP (pb DC-PB1 keeps ACP chain; DC-PB7a / sprint 24 H8).
2. opalsubscription events (fix the listener wiring or delete the listeners and the four events).
3. PayPal vault + `blPayPalRemoveBy*`; Stripe `blStripeRemoveBy*` (implement or remove; admin GraphQL schema change).
4. Stripe disputes: flag (sprint 23 T3) or delete the handler.
5. BC policy for payment-base removals: minor bump with CHANGELOG "Removed" (no in-house consumer uses the items,
   checked across 7 repos) or wait for the next major.
