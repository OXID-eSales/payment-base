# Dead-code check — paypal

**Repo:** `source/extensions/paypal`, branch b-7.4.x @ 8538946. Read-only.

**Method.** I triaged all 28 heuristic candidates and swept every class in `src/` (203 files). For each class or interface I used whole-word grep over the production code: paypal `src/`, `views/` (twig and lang files), `resources/js`, `metadata.php`, `menu.xml` and `services.yaml`, plus payment-base and one-page-checkout (`src/`, `views/`, `services.yaml`). Tests were counted separately.
- **Live wiring:** I resolved every `services.yaml` alias to see whether something injects it (constructor type-hint or `ContainerFacade::get`), and checked every tag against its `!tagged_iterator` consumer in payment-base and OPC. Every handler's `getHandledEventClass()` was checked against a `new XEvent` / dispatch site.
- **Endpoints and templates:** `fnc` endpoints were checked against twig forms, JS fetch calls and server-built URLs. Twig extension blocks were checked against the apex and admin_twig parent templates.
- **Settings and translations:** each setting was checked for a getter that something calls. Each lang key was checked against the twig, PHP and JS that uses it.
- **Public methods:** a per-method call count over the same production paths, with declaration lines excluded.
- **Class ids:** a Python check confirmed that all 151 FQCNs in `services.yaml` have a class file (0 missing).
- **Previous review:** its claims were checked against `docs/dev_log/20261030/reports/violations-review-paypal.md` and sprints 18, 19 and 20.

Line counts are `wc -l` of the files.

## DEAD (no production reference, or reachable only through an entry point nothing calls)

| Verdict | Class / item | Lines | Tests that only exist for it | Evidence | Removal note (deps, risk) |
|---|---|---|---|---|---|
| DEAD | `Adapter/PayPalStatusMapper` | 60 | `Unit/Adapter/PayPalStatusMapperTest`, `PayPalStatusMapperEdgeCasesTest` | 0 references outside the file; not in services.yaml (autowire resource covers Service/ only) | Previous review **confirmed**. Delete the class and both tests. No risk. |
| DEAD | `Adapter/PayPalVaultAdapter` + `PayPalVaultAdapterInterface` | 111 + 40 | none | Interface implemented only by this class; neither is type-hinted or registered (services.yaml:577-582 comment: "consumed by unit tests only") | Delete both and the comment block at services.yaml:575-582. No risk. |
| DEAD | `EventSystem/Event/PayPalCancelUrlBuildEvent`, `PayPalSuccessUrlBuildEvent` | 33 + 37 | none | 0 references anywhere | Delete. No risk. |
| DEAD | `Service/Result/CheckoutReturnResult` (new) | 37 | none | `GraphQL/Controller/PayPalCheckout.php:18` imports **payment-base's** `GraphQL\DataType\CheckoutReturnResult`; the PayPal copy has no reference | Delete. No risk. |
| DEAD (unreachable chain) | `Controller/PaymentController` (module `extend` of core PaymentController) | 97 | `PhpStan/phpstan-bootstrap.php:19-22` alias only | Its only method is `execute()`. Core `PaymentController` has no `execute`. The payment step posts `fnc=validatepayment` (apex `payment.html.twig:54`, payment-base override :28). No twig, JS or redirect anywhere sends `cl=payment&fnc=execute`. | Remove `metadata.php` `extend` entry `PaymentController::class => PayPalPaymentController::class` (needs a module re-activation). |
| DEAD (same chain) | `Event/PayPalPaymentExecuteEvent` | 28 | none | Dispatched only by the class above; **no handler** for it in any module | Delete with B5. |
| DEAD (same chain) | `Event/PayPal3DSRequiredEvent` + `Handler/PayPal3DSRequiredHandler` (services.yaml:381-384) | 39 + 49 | `Unit/EventSystem/Handler/PayPal3DSRequiredHandlerTest`, `Unit/Security/F14_OpenRedirectTest` | Dispatched only when context `payerActionUrl` is set, and nothing in src ever sets it (only read at `PaymentController.php:61`) | Delete with B5. |
| DEAD (same chain) | `Service/PayerActionUrlValidator` | 52 | `PayerActionUrlValidatorTest`, `PayerActionUrlValidatorAdversarialTest` (F14 above) | Only consumer is `PayPal3DSRequiredHandler` | Delete with B5. |
| DEAD (same chain) | `Event/PayPalPaymentReturnEvent` + `Handler/PayPalPaymentReturnHandler` (no-op "marker") + `Handler/PayPalPaymentStatusHandler` (services.yaml:386-394) | 25 + 39 + 62 | `Unit/EventSystem/Handler/PayPalPaymentStatusHandlerTest` | Dispatched only from `PaymentController::execute():74`. Even then the context has no contract and no `paypalStatus`, so StatusHandler would return early. | Delete with B5. |

## UNWIRED (registered, but never injected, tagged, dispatched or consumed)

| Verdict | Class / item | Lines | Tests that only exist for it | Evidence | Removal note (deps, risk) |
|---|---|---|---|---|---|
| UNWIRED | `Adapter/OxidShopOrderService` (services.yaml:163-168) | 245 | `Unit/Adapter/OxidShopOrderServiceTest` (13 tests) | `ShopOrderServiceInterface` alias removed 2026-09-01 (yaml comment :166). No type-hint and no `ContainerFacade::get` of the concrete class. payment-base's `Adapter\OxidShopOrderService` does the work. | Previous review **confirmed** (D-6). Delete the class, the test and yaml lines 163-168. No risk. |
| UNWIRED | `Service/RequestLogService` + `RequestLogServiceInterface` (yaml :89 exclude, :126-134), `Psr\Log\NullLogger: ~` (:136, only used as its `$logger`) | 128 + 34 | `Unit/Service/RequestLogServiceTest` (holds the `CapturingLogger` helper), `RequestLogServiceRedactionTest`, `Unit/Security/F19_SensitiveDataInExceptionsTest`, `F23_ExceptionLoggingTest` | Interface alias exists; nothing type-hints either id. `logRequest`/`logException` have 0 production calls. | Previous review **confirmed**. Sprint 18 (L5) adds a new `PayPalWebhookFileLoggerFactory` and does **not** revive this class, so delete it. Check that `CapturingLogger` has no users outside these 4 tests (none found). |
| UNWIRED | `Service/OxpaidReconciliationService` + Interface (yaml :501-503) | 85 + 25 | `Unit/Service/OxpaidReconciliationServiceTest` | Alias only; no type-hint (payment-base owns OXPAID through `OrderPaymentStateService`) | New finding. Delete. Low risk. |
| UNWIRED | Vault webhook handlers `VaultPaymentTokenCreatedHandler`, `VaultPaymentTokenDeletedHandler` (yaml :588-589) | 37 + 44 | `Unit/WebhookHandler/VaultWebhookHandlersTest` | Not in `WebhookContractFulfillmentHandler $handlers` (yaml :552-559), so `VAULT.PAYMENT-TOKEN.*` deliveries answer `ignored` | Previous review **confirmed**. Sprint 19 W3 says "register or delete (sprint 17 decides)". Recommendation: delete, because no vault flow exists (adapter dead, customer service unwired, setting never read). |
| UNWIRED | `Service/PayPalCustomerService` + Interface + `Result/PayPalCustomer` (yaml :584-586) | 87 + 36 + 28 | `Unit/Vault/PayPalCustomerServiceTest` | Interface never injected. Only `PayPalCustomerService::PROVIDER` is read, and only by the dead `VaultPaymentTokenDeletedHandler:41`. | Delete together with the vault handlers (B7). The `oe_payments_customer` table belongs to payment-base and stays. |
| UNWIRED (kept) | `Service/ReturnSessionSecurityService` | 57 | `Unit/Service/ReturnSessionSecurityServiceTest`, `Unit/Security/F20_SessionFixationTest` | `validate()` never called. Only `METADATA_SESSION_ID` is read (`PayPalContractCreationHandler:48`). | **Do NOT delete.** Sprint 20 R1 (SEC-1 hotfix) **wires** it into `paypalCheckoutSuccess` (owner + session + `token` + provider check) and replaces F20 with controller tests. It stays out of every batch below. |
| UNWIRED (events with no subscribers) | `PayPalConnectEstablishedEvent` (dispatched `PayPalConnect:80`), `PayPalConnectDisconnectedEvent` (`PayPalConnectionService:54`) | ~25 each | none / 1 | Dispatched, but no handler in paypal, payment-base or OPC | Optional. Keep as an extension point, or drop the dispatch, the event and the nullable dispatcher in `PayPalConnect`. Low value. |

## Below-class findings

| Verdict | Item | Lines | Tests | Evidence | Removal note |
|---|---|---|---|---|---|
| DEAD fnc | `PayPalOrderController::execute()` | 64-67 | none | No `cl=PayPalOrderController&fnc=execute` anywhere. The footer form posts `paypalCheckoutStart` (`paypal_footer.html.twig:53-54`); JS uses `createOrderJson`, `paypalCheckoutSuccess` and `paypalCheckoutCancel` (`PayPalCheckoutFooter:144-154`, `CheckoutSessionService:100,119`). | **Cascade:** `handleCheckout()` is then called only with SmartButtons, and `resolveUxMode()` forces `paypal_ux=SmartButtons`. That makes the redirect tail (:195-198), `resolveUxMode()` (:282-293) and `Service/UxModeResolver` (63 lines, `UxModeResolverTest`) dead. Do this **after** the sprint 20 R1 SEC-1 hotfix, which edits this controller. |
| DEAD methods | `Admin/PayPalConnect`: `getLiveSignUpLink`, `getSandboxSignUpLink`, `getPartnerJsUrl`, `getPayPalModuleId`, `buildReferralLink` | 46-55, 96-123 | none | `$_sThisTemplate = ''`, so the controller renders nothing. `module_config.html.twig` calls these getters on `oView` = `Admin/ModuleConfiguration`, which has its own copies. | Delete. The `fnc`s `autoConfigurationFromCallback` and `disconnect` are live (twig :37, :95). |
| DEAD methods | `Admin/ModuleConfiguration::getPayPalModuleId`, `isPayPalModule` | 89-115 | none | The twig gates on `oView.getEditObjectId() == 'oe_payments_paypal'` (comment says why) | Delete. |
| DEAD methods | `Core/ViewConfig::isPayPalSandbox`, `getPayPalMode` (only caller is `isPayPalSandbox`) | 32-40 | none | 0 twig uses | Delete. `getPayPalClientId` and `isPayPalPaymentId` are live. |
| DEAD methods | `PayPalCheckoutFooter::getPayPalLocale`, `getPaymentId` | 118-136 | `getPaymentId`: 1 test | 0 twig or PHP calls | Delete. |
| DEAD methods (setting never applied) | `ModuleConfigurationService(+Interface)::isRemoveByBillingCountry`, `isRemoveByBasketCurrency` | 116-125 | none | 0 callers. Settings `blPayPalRemoveByBillingCountry` / `blPayPalRemoveByBasketCurrency` are only *displayed* (`OxidPayPalAdminSettings`, `AdminPayPalConfig`), so the admin switch has **no effect**. | Product decision: implement the filter, or remove the getters, both settings (metadata, lang, admin GraphQL fields: a schema change). |
| DEAD methods | `ModuleConfigurationService(+Interface)::isVaultingEnabled`, `getPartnerId` (reads env `OE_PAYMENTS_PAYPAL_PARTNER_ID`; partner ids now come from `PayPalPartnerConfig`), `isConfigured` (1 test) | 147-165 | `isConfigured` 1 | 0 callers | Delete. `isVaultingEnabled` goes with B7, along with settings `blPayPalEnableVaulting` / `sPayPalVaultConsentText` (only displayed, never applied). |
| DEAD stubs | `PayPalReferralService(+Interface)::fetchSellerCredentials` (returns null), `disconnect` (returns true) | 86-98 | `Unit/Connect/PayPalReferralServiceTest` (stub assertions) | 0 callers. `PayPalConnect::disconnect` calls `PayPalConnectionService::disconnect`, not this one. | Previous review **confirmed**. Delete both from the interface and the class, plus the `@see` in `Result/ConnectCredentials.php:13`. |
| DEAD block | `WebhookContractResolver.php:44-47` empty `if (isset($resource['custom_id'])) { /* future hook */ }` | 4 | none | Empty body | Previous review **confirmed**. Delete. |
| DEAD methods (small) | `PayPalDefinitions::supportsCurrency`, `supportsCountry`; `FundingSource::toJsMap`; `StaticContent(+Interface)::getDashboardUrl` (:25), `getRefundUrl` (:40); `ControllerRequestHelper::getParam` (:20); `PayPalCaptureRequestEvent::isFullCapture` (:37), `getCaptureId` (:49); `PayPalRefundRequestEvent::getRefundId` (:35); `PayPalConfigSnapshot::secretSet` (:47) | ~60 total | a few tests each (1-3) | 0 production callers | Delete with their test cases. Check `toJsMap` against the bundled JSON (`resources/js/funding_sources.json` is the JS source; `toJsMap` has no PHP caller). |
| Unused translation keys | admin_twig en/de: `PAYPAL_ORDER_REFUND`, `PAYPAL_VALIDATION_ERRORS`; twig en/de: `PAYPAL_CHECKOUT_CONTINUE`, `PAYPAL_CHECKOUT_CANCEL`, `PAYPAL_CHECKOUT_FALLBACK_NOTICE`, `PAYPAL_CHECKOUT_STATUS_READY`, `PAYPAL_CHECKOUT_STATUS_LOADING` | 7 keys x 2 langs | none | 0 hits in twig, PHP or JS, including payment-base views; no dynamic `PAYPAL_` prefix building found | Delete. `SHOP_MODULE_*` / `SHOP_MODULE_GROUP_*` keys are used by convention, so they stay. |
| Empty file | `menu.xml` (only `<OXMENU id="NAVIGATION_ESHOPADMIN"/>`, comment says "Delete once all forks merged") | 13 | none | No entries | Delete (module re-activation). |
| Interface-forced, never called | `PayPalPaymentHandler::confirmPayment` | — | none | Required by payment-base `PaymentHandlerInterface:47`; OPC never calls it (0 callers in any module) | Not removable in paypal alone. Report it to payment-base. |
| Ladders (unreachable when DI is complete) | `services.yaml:632` `$shopAdapter: '@?…ShopAdapterInterface'` + `CheckoutEventContextBuilder:36,47-48` `?->`/`??` fallbacks; `ModuleConfigurationService:35,143`; `PayPalOrderController:84-85,218-219,318` (`getShopAdapter()?->… ??`), `:353-356` (`?? new ControllerRequestHelper()`), `:100,178` (`getEventDispatcher()?->`, a null dispatcher silently falls to "no approval URL"), `:285-287` (`resolver === null`); `?PayPalTransactionRecorderInterface = null` in `CaptureService:42,137`, `RefundService:34,63`, `PayPalAuthorizationRecordHandler:29,63`, `PayPalCaptureRecordHandler:31,75`; `OrderRefundViewDataProvider:34,63`; `PayPalAdapter:40,180` (`signatureVerifier === null`: the factory always passes one; `webhookId === null` **is** reachable); `WebhookController:51-79` (`resolveX()?->`, C-2) | — | some tests construct with null | Every id is defined in this module's `services.yaml` (ShopAdapterInterface :152, recorder alias :276, TransactionHistory :497) or in payment-base | Make the parameters non-nullable and fail loudly (rule "no fallbacks"). Update the tests that pass null. `WebhookController` is rewritten by sprint 19 W3, so skip it here. |
| Ladder, **not** dead | `CheckoutOrderApprovedHandler:41-42,59` and `PaymentCaptureCompletedHandler:34,73` nullable `settler`/`contractCommit` | — | — | The `!== null` half is always true, but the fallback still runs for **closed** contracts. In `PaymentCaptureCompletedHandler:83-89` it adds to `capturedAmount` on a committed contract (redelivery double-count risk). | Drop the nullability. Review the closed-contract branch as a behaviour question; do not just delete it. |
| Not found | `services.yaml` ids without a class (0 of 151); commented-out code (none); unrendered twig templates or blocks (none: all 5 templates are rendered or extend existing parent blocks `checkout_payment_longdesc`, `checkout_order_next_step_side`, `admin_module_config_form`); unloaded JS (none: `paypal.min.js` = rollup of `resources/js/{index,sdk,controllers/*,funding_sources.json}`, loaded by `paypal_footer.html.twig:99`) | | | | |

## LIVE (heuristic candidates, wiring)

- `CurlPayPalRestTransport`: aliased as `PayPalRestTransportInterface`, which `PayPalAdapterFactory` and `ConfiguredPayPalApiReader` inject.
- `CurlConnectHttpInvoker`: aliased as `ConnectHttpInvokerInterface`, which `PayPalReferralService` injects (used by the onboarding callback).
- **"Three HTTP clients" is refuted as dead code.** All three are live: the PayPal SDK for orders, captures and refunds; the curl transport for webhook signature checks and the admin API; the curl invoker for partner referral. That is duplication (D-5), not dead code.
- `OxidSessionAdapter`: SessionAdapterInterface alias, used by `ConnectStateService` and `AdminValidationFeedback`. payment-base also binds this id; the last merged definition wins.
- `OxidShopAdapter`: ShopAdapterInterface alias, used by `PayPalTransactionRecorder`, `ModuleConfigurationService` and `CheckoutEventContextBuilder`.
- `PayPalPaymentPanelProvider`: tag `oe.payment.admin_panel`, collected by a payment-base tagged_iterator.
- `PayPalClientConfigProvider`: tag `payment_base.client_config_provider`, collected by a payment-base tagged_iterator.
- `PayPalAuthorizationRecordHandler` and `PayPalCaptureRecordHandler`: `payment.event_handler` on `PaymentAuthorizedEvent`.
- `PayPalCaptureRequestHandler`, `PayPalRefundRequestHandler`, `PayPalCancelAuthorizationRequestHandler`: their events come from `OrderActionDispatcher` and the translator.
- `PayPalCheckoutSessionHandler`: its event is dispatched by `PayPalOrderController` and `PayPalPaymentHandler`.
- `PayPalEventTranslator`: tag `oe.payment.event_translator`, fed through `EventBroker` to payment-base `RefundIntentHandler`.
- `RegistryShopEnvironment`: ShopEnvironmentInterface, injected into `CheckoutEventContextBuilder`.
- `ConnectStateService`: injected into `PayPalReferralService`.
- `PayPalAttemptCloser`: tag `oe.payment.attempt_closer`, consumed by payment-base `$closers`.

## Totals

- **DEAD:** 6 standalone items (~318 lines) + the legacy PaymentController / 3DS / payment-return chain, 8 files (~391 lines). About **709 lines**; 7 test files.
- **UNWIRED, to delete:** OxidShopOrderService, RequestLogService(+I), OxpaidReconciliationService(+I) and the vault cluster (2 handlers, CustomerService+I, Result/PayPalCustomer). About **748 lines**; 8 test files (13 + ~20 test methods).
- **UNWIRED, kept:** ReturnSessionSecurityService (57 lines, SEC-1) and 2 connect events.
- **Below-class:** 1 dead `fnc` (+ UxModeResolver cascade, ~75 lines), ~25 dead public methods (~230 lines), 2 stubs, 1 empty block, 14 lang entries, `menu.xml`, ~15 nullable-collaborator ladders.
- **Overall:** about **1,750 removable lines**, plus the ladder simplification.

**Previous review:**
- **Confirmed:** PayPalStatusMapper, RequestLogService, OxidShopOrderService, Vault handlers (unregistered), Referral stubs, `WebhookContractResolver:44-47`, ReturnSessionSecurityService (unused today, but it is wired by SEC-1, so it is not deleted).
- **Refuted:** "three HTTP clients" as dead code.

## Proposed deletion order (one commit each; `--testsuite Unit` and `Integration` green + phpstan/phpcs after each; update `tests/PhpStan/phpstan-baseline.neon` entries that point at removed files)

1. **B1 — zero-reference files and blocks:**
   - `PayPalCancelUrlBuildEvent` and `PayPalSuccessUrlBuildEvent`
   - `PayPalVaultAdapter` + interface, and the yaml comment :575-582
   - `Service/Result/CheckoutReturnResult`
   - the `WebhookContractResolver:44-47` block
   - the 14 unused lang entries
2. **B2 — dead getters and stubs:**
   - `PayPalConnect` getters + `buildReferralLink`
   - `ModuleConfiguration::getPayPalModuleId` / `isPayPalModule`
   - `ViewConfig::isPayPalSandbox` / `getPayPalMode`
   - Footer `getPayPalLocale` / `getPaymentId`
   - Referral `fetchSellerCredentials` / `disconnect` (interface + class + tests)
   - the small unused methods row (+ their test cases)
   - `ModuleConfigurationService::getPartnerId` / `isConfigured`
3. **B3:** `PayPalStatusMapper` + 2 tests; `Adapter/OxidShopOrderService` + test + yaml :163-168.
4. **B4:** `RequestLogService` + interface + yaml :89, :126-136 (incl. `NullLogger`) + 4 tests; `OxpaidReconciliationService` + interface + yaml :501-503 + test.
5. **B5 — legacy PaymentController chain:**
   - Remove the `metadata.php` `extend` entry.
   - Delete `Controller/PaymentController` and the `PaymentController_parent` alias in `phpstan-bootstrap.php`.
   - Delete the events `PayPalPaymentExecuteEvent`, `PayPalPaymentReturnEvent` and `PayPal3DSRequiredEvent`.
   - Delete the handlers `3DSRequired`, `PaymentReturn` and `PaymentStatus`, plus yaml :377-394.
   - Delete `PayerActionUrlValidator` and the tests 3DSRequiredHandlerTest, F14, PaymentStatusHandlerTest and PayerActionUrlValidator(Adversarial)Test.
   - Re-activate the module, then run one classic and one OPC PayPal checkout (Playwright).
6. **B6 — vault cluster** (record the sprint 17 decision in the commit):
   - Delete the two Vault handlers, `PayPalCustomerService` + interface + `Result/PayPalCustomer`, yaml :584-589 and `isVaultingEnabled`.
   - Optional separate commit: drop the settings `blPayPalEnableVaulting` / `sPayPalVaultConsentText` (metadata, lang, `OxidPayPalAdminSettings`, `AdminPayPalConfig` fields). This is an admin GraphQL schema change, so coordinate with the admin client.
7. **B7 — after sprint 20 R1 (SEC-1) has landed:**
   - Delete `PayPalOrderController::execute()`, the redirect tail of `handleCheckout`, `resolveUxMode` and `Service/UxModeResolver` + test.
   - Delete `menu.xml` (re-activation).
8. **B8 — ladders:**
   - Make the nullable collaborators listed above non-nullable; change `services.yaml:632` from `@?` to `@`.
   - Remove the `?->` / `??` fallbacks and fix the tests that pass null.
   - Leave `WebhookController` to sprint 19 W3, and the `PaymentCaptureCompletedHandler` closed-contract branch to a behaviour review.
9. **Product decision (no batch until decided):** `blPayPalRemoveByBillingCountry` / `blPayPalRemoveByBasketCurrency`. Implement them or remove them.
