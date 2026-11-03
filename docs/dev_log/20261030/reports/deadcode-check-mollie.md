# Dead-code check — mollie-payment

**Method.** Read-only, detached `origin/b-7.4.x @ 5f9fc0e`. Every class in the candidate list was traced through services.yaml (tags, `!tagged_iterator`, aliases, explicit args) to its consumer or dispatcher. The webhook handlers were matched against `MollieWebhookProcessor::determineEventType()` and `MollieStatusMapper`, and the event handlers against `MollieEventTranslator` and `OrderActionDispatcher`. After that came the wider checks:
- a class-reference scan over comment-stripped PHP in mollie-payment, one-page-checkout and payment-base `src/`, counting a class as used through itself or any interface it implements;
- a public-method call scan over the same code plus twig, JS, services.yaml and metadata.php;
- metadata settings, controllers, templates and extend entries checked against their readers;
- translation keys, including the dynamically built `MOLLIE_RETURN_*`, `MOLLIE_VALIDATION_LABEL_*` and core `SHOP_MODULE_*` keys;
- the JS bundle entry and the Stimulus controllers used by the templates;
- that every FQCN in services.yaml and metadata.php exists (all of them do);
- the module's service definitions compared with payment-base's own services.yaml;
- nullable "legacy" constructor arguments;
- commented-out code (none found).

## Findings

| Verdict | Class / item | Lines | Tests that only exist for it | Evidence | Removal note (deps, risk) |
|---|---|---|---|---|---|
| **DEAD** | `MollieRefundAdapterInterface::getRefund()`, plus `MollieAdapter::getRefund()` and `LazyMollieAdapter::getRefund()` | ~11 + 4 + 1 | none (only an anonymous stub at `tests/Unit/Service/AdminRequestIdempotencyTest.php:94` implements it) | No production code calls `->getRefund(` in mollie-payment, OPC or payment-base. Refund rows come from `listRefunds()`. | Remove from the interface and both adapters, and delete the stub method in the test. Low risk. |
| **DEAD** | `MollieCaptureRequestEvent::isFullCapture()` | ~4 (l.33) | none | Never called, not even in tests. | Delete. No risk. |
| **DEAD** | Translation key `MOLLIE_VALIDATION_INVALID_USER_DATA` (en l.32, de l.31) | 2 | `UserDataValidationMessageFormatterTest.php:92` lists it as "must exist in both languages" | It is no longer referenced in src, views, JS, OPC or payment-base. MOL-15 replaced it with `MOLLIE_VALIDATION_FIELD_INVALID` and `MOLLIE_VALIDATION_REVIEW_ADDRESS`, and only an e2e docblock still mentions it. | Delete the key in both languages and drop that line from the test's key list. |
| **TEST-ONLY** | `CheckoutStartOutcome::isUnavailable()` | 4 (l.83) | `CheckoutSessionStarterTest` l.184, l.203 (assertions only) | Production callers read `isNotOrderable`, `hasUserDataProblems` and `isRedirect`. "Unavailable" is the fall-through case. | Optional. Either delete it and assert `!isRedirect()` in the tests, or keep it as an API for readability. |
| **TEST-ONLY** | `MollieConfigSnapshot::keySet(string $mode)` | 4 (l.35) | `MollieConfigSnapshotTest` l.51–52 | The GraphQL type reads the `testKeySet` and `liveKeySet` properties directly. | Delete it and its two assertions. Low risk. |
| **UNWIRED** | `Controller/Webhook/WebhookIpAllowlistGuard` | 67, plus services.yaml l.521–522 | `WebhookGuardChainTest::testIpAllowlist_*` (2 tests and a `#[CoversClass]`), `WebhookSecurityParityTest::testF3_IpAllowlistGuardRejectsUnlistedSource` | It is registered but deliberately left out of `WebhookGuardChain $guards` (the yaml comment says Mollie publishes no fixed webhook IP ranges). Nothing injects it. | Delete the class, its yaml entry and the 3 tests plus the CoversClass attribute. Check that the parity doc or test does not require the F3 row, or mark F3 "N/A for Mollie". Low risk. |
| **REDUNDANT (local copy)** | `Adapter/OxidSessionAdapter`, plus yaml l.187–191 (class and `SessionAdapterInterface` alias) | 52 + 5 | `tests/Integration/Adapter/OxidSessionAdapterTest.php` | The class body is identical to `payment-base/src/Adapter/OxidSessionAdapter`. payment-base binds `SessionAdapterInterface` itself (pb services.yaml l.455–465), and its comment says "P-Mollie … delete those". | Delete the class, both yaml entries and the integration test. Mollie consumers (`InFlightCheckoutReplay`, `AdminValidationFeedback`) inject the interface, so nothing changes. Low risk: payment-base must be active, and Mollie already relies on it for ContractRepository, CommitService and others. |
| **REDUNDANT (local copy)** | yaml definitions that copy payment-base's own bindings: `EventListenerProviderInterface` (l.396), `EventDispatcherInterface` (l.402), `TransactionRepositoryInterface` (l.438), `ContractServiceInterface` (l.793) | ~25 yaml | — | Each id is defined identically in payment-base's services.yaml (l.174, 180, 844, 269). The "Mollie activates standalone" comments date from before Mollie required active payment-base services. | Delete the four blocks and their comment banners. Verify with module re-activation plus the Integration suite. The other shared ids (`EventBroker`, `OrderPaymentStateService`, `ContractFulfillmentService`, `OrderPaymentCompletedHandler`, `WebhookLogRepository`, `EarlyOrderCreationHandler`, `StockRestoration`, `Acp*`) are **not** bound by payment-base, so they must stay. |
| **LEGACY BRANCH** | `WebhookContractFulfillmentHandler`: optional `?ContractCommitServiceInterface $contractCommit = null` (l.57), the `if ($this->contractCommit !== null)` fallback (l.273–286) and `advanceToAuthorized()` (l.366–380) | ~25 | `WebhookContractFulfillmentHandlerTest` builds the handler **without** the commit service, so these tests only exercise the legacy path: `testHandlePaymentAuthorized_FromNotFinished_AuthorizesContractAndRecordsTransaction` (l.404), `…FromPending_SwallowsTransitionToPendingDomainException` (l.419), `…SwallowsDomainExceptionFromAuthorizeStep` (l.432), and probably `…DoesNotFulfillOrTouchOxpaid` (l.444) | In production the argument is always autowired, because payment-base binds `ContractCommitServiceInterface` (pb yaml l.190). The "consumer whose services.yaml predates it" case cannot happen inside this module. | Make the argument required, delete the fallback and `advanceToAuthorized()`, and give the base test a mocked commit service. Delete or port the 3–4 legacy tests (`…AuthorizedCommitTest` already covers the live path). Medium risk: it changes test fixtures. |
| **LEGACY BRANCH (minor)** | Nullable test-convenience arguments whose null branch production never takes: `CheckoutSessionStarter` `?replay` / `?logger` (l.52–53, the yaml passes both); `RefundService` `?TransactionAuditRecorder` (l.55, autowired from the Service sweep); `MollieReturnResolver` `?config` / `?logger` (l.45–46, the yaml passes both; the guard is at l.67); `OrderActionDispatcher` `?eventDispatcher` (l.47, the yaml passes it; the guard is at l.81); `MolliePaymentHandler` `?notOrderableMessages ?? new NotOrderableItemsMessages(new OxidLanguageTranslator())` (l.71–79, the yaml passes it) | ~15 | unit tests that build these objects with fewer arguments | Under complete DI the null branch is unreachable. | Optional cleanup. Making the arguments required means updating many unit-test constructors. Do it last, or skip it. |

## LIVE (candidate list)
- **OxidShopAdapter**: alias for payment-base's `ShopAdapterInterface`, which payment-base does not bind. Mollie uses `getShopUrl` and `getShopId`. Note: Stripe and PayPal bind the same id, so whichever services.yaml merges last wins, and Stripe would then get Mollie's `isTestMode`.
- **AdminActionBounds**: alias for `AdminActionBoundsInterface`. Consumers: PanelProvider, ViewDataBuilder, ReleaseRule.
- **MolliePaymentPanelProvider**: tagged `oe.payment.admin_panel`.
- **ReconcileOxpaidCommand**: tagged `console.command mollie:reconcile-oxpaid`.
- **ReconcilePaidContractsCommand**: tagged `console.command mollie:reconcile-paid`.
- **MollieCancelAuthorizationRequestHandler**: `payment.event_handler`. `OrderActionDispatcher::cancel` → translator.
- **MollieCaptureRequestHandler**: `payment.event_handler`. `OrderActionDispatcher::capture` → translator.
- **MollieRefundRequestHandler**: `payment.event_handler`. `OrderActionDispatcher::refund` → translator.
- **MollieReleaseAuthorizationRequestHandler**: `payment.event_handler`, dispatched directly by `OrderActionDispatcher::release`.
- **MollieEventTranslator**: tagged `oe.payment.event_translator`, collected by EventBroker.
- **CaptureCompletionPolicy**: interface alias injected into CaptureService.
- **MollieAttemptCloser**: tagged `oe.payment.attempt_closer`, collected by `PaymentAttemptClosers`.
- **MollieClientConfigProvider**: tagged `payment_base.client_config_provider`.
- **MollieOrderDataProvider**: interface alias injected into CheckoutPaymentService.
- **OxidContractLinkedOrderUpdater**: interface alias. Consumers: fulfillment handler, cancel service, release service.
- **OxidStockRestorationService**: alias for `StockRestorationServiceInterface`, injected into RefundService.
- **Webhook handlers** (Paid, Failed, Expired, Canceled, Authorized, Refunded, ChargebackCreated): tagged `mollie.webhook_handler`. Each handled status is produced by `determineEventType()`.

Outside the candidate list, everything else checked was LIVE:
- **Settings**: all 8 metadata settings are read, by ModuleConfigurationService, `OxidMollieAdminSettings` or ViewConfig.
- **Controllers**: both registered controllers are used. `checkoutReturn` is the return URL that `MollieCheckoutSessionHandler` builds.
- **ViewConfig**: all 10 getters are used from twig.
- **Templates**: all 4 templates are rendered. The `themes/default` extension applies to every theme.
- **JS**: the bundle is loaded by `getMollieJsPath()`, and all 3 app.js controllers are used in order.html.twig. The 2 controllers registered inside the footer widget are used there.
- **Big classes**: every public method of MolliePaymentHandler, MollieAdapter (except `getRefund`), RefundService and WebhookContractFulfillmentHandler is called. `confirmPayment` is required by payment-base's `PaymentHandlerInterface`.
- **No dead classes outside the candidate list**: every other src class has a code or yaml consumer.
- **Deleted copies stay deleted**: no Mollie `OxidShopOrderService` copy remains (deleted 2026-10-14).

## Totals
- **DEAD**: 3 items (one interface method plus its 2 implementations, 1 method, 1 translation key). About 20 lines plus 1 test line.
- **TEST-ONLY**: 2 methods, about 8 lines plus 4 assertions.
- **UNWIRED**: 1 class (`WebhookIpAllowlistGuard`). 67 lines, 2 yaml lines, 3 tests.
- **REDUNDANT copies**: 1 class (52 lines), 6 yaml definitions (about 30 lines), 1 integration test.
- **Legacy branches**: 1 meaningful (about 25 lines, 3–4 tests) and 5 minor nullable arguments.
- **Candidates classified LIVE**: 23 of 24. The one exception, OxidSessionAdapter, is the redundant copy above.

## Proposed deletion order (one commit each, tests green)
1. **Pure dead members.** Remove `getRefund` from the interface, both adapters and the test stub. Remove `MollieCaptureRequestEvent::isFullCapture`. Remove the translation key in en and de, and its test list line. Run Unit.
2. **Test-only methods.** Remove `CheckoutStartOutcome::isUnavailable` (adjust the 2 assertions) and `MollieConfigSnapshot::keySet` (drop its assertions). Run Unit.
3. **Unwired guard.** Delete `WebhookIpAllowlistGuard`, its yaml block and its 3 tests. Run Unit, Integration and phpstan.
4. **Redundant session adapter.** Delete `Adapter/OxidSessionAdapter`, yaml l.187–191 and `tests/Integration/Adapter/OxidSessionAdapterTest.php`. Run Integration and a module deactivate/activate.
5. **Redundant payment-base binding copies.** Delete yaml `EventListenerProviderInterface`, `EventDispatcherInterface`, `TransactionRepositoryInterface` and `ContractServiceInterface`. Run Integration and re-activate in a shop that has Mollie and payment-base only, then again with Stripe and PayPal.
6. **Commit-service legacy ladder.** Make `$contractCommit` required, delete the fallback and `advanceToAuthorized()`, and port `WebhookContractFulfillmentHandlerTest` to a mocked commit service. Run Unit, Integration and the MollieStandard manual-capture e2e.
7. **Optional.** Make the nullable test-convenience arguments required (CheckoutSessionStarter, RefundService, MollieReturnResolver, OrderActionDispatcher, MolliePaymentHandler) and update the unit-test constructors.
