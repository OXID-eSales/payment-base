# Sprint 15 / S8 — Gates, consumers, hand-over (DONE 2026-10-06)

**Sprint:** [sprint-15-GRAPH-QL-headless-checkout.md](../sprints/sprint-15-GRAPH-QL-headless-checkout.md) · **Branch:** `b-7.4.x-GRAPH-QL` (pushed)

## payment-base gates (final)

| Gate | Result |
|---|---|
| Unit (`phpunit.xml`) | **1548** green, 6 pre-existing skips (1392 before the sprint) |
| Integration (shop PHPUnit, `tests/phpunit-integration.xml`) | **148** green, 2 skips (1 pre-existing, 1 = the placeOrder-guard case that waits for a provider to declare its handler contract-first) |
| phpcs (PSR-12) | clean |
| PHPStan level max | No errors |
| phpmd (strict, baseline) | clean |

## Consumers against this branch (dev shop, shop PHPUnit unless noted)

| Module | Unit | Integration | Notes |
|---|---|---|---|
| stripe | **1587** green (standalone `phpunit-unit.xml`, 8 warnings / 22 deprecations pre-existing) | **100** green, 1 skip | first run went fatal: five anonymous test doubles implement `ContractRepositoryInterface`, and S3 had added a method to it → **fixed in S8** (`OpenAttemptFinderInterface`, see below) |
| mollie-payment | not runnable here: its `bootstrap-unit.php` needs the module's own `vendor/` (not installed in this shop; CI installs it) | **38** green, 3 skips | |
| paypal | **449** green | **12** green, 6 skips | |
| one-page-checkout | **542**: 1 error, pre-existing — `OxidEsales\Payments\Mollie\Controller\PaymentController_parent` not found (Mollie's chain class is not generated in this shop; same cause as the Stripe `phpunit.xml` fatal noted in S1) | **29** green, 4 skips | |
| opalreturns | **353** green | **14** green; CrossModule suite has no tests | |

## Fixed in S8

- `ContractRepositoryInterface` is back to its pre-sprint shape. The headless lookup is
  `Repository\OpenAttemptFinderInterface::findOpenByUserAndBasketId()`, implemented by `DoctrineContractRepository`
  and bound as an alias of the repository service. `EarlyOrderCreationHandler` takes `?OpenAttemptFinderInterface
  $openAttemptFinder = null` as its 7th argument: without it (the providers' current services.yaml) the registry-only
  behaviour stays; with it the headless retire-by-basket fallback is active.

## What the sprint delivered (payment-base, `b-7.4.x-GRAPH-QL`, 8 commits + docs)

| Story | Lands |
|---|---|
| S1 | `CheckoutBasketProviderInterface` — session basket or `oxuserbaskets` row by id; `CreateOrderRequest::$basketId`; `DeliveryAddressHashService` writes `$_POST` (defect fix) |
| S2 | `CheckoutContextInterface` — session or persisted (`oe_payments_sessions`) per `HeadlessCheckoutScope`; registry / skip guard / cleaner on it; `SessionAdapterInterface` bound here |
| S3 | `CheckoutAttemptGuardInterface` / `IdempotentAttemptGuard` on `oe_payments_idempotency`; `basket_id` on contracts; retire-by-basket via `OpenAttemptFinderInterface`; responder skips `sess_challenge` under a headless scope |
| S4 | `Service\Commit\ContractCommitService` — commit when the provider says paid; dispatcher ids bound here |
| S5 | `Checkout\ReturnUrl\AllowListReturnUrlPolicy` + setting `sPaymentBaseHeadlessReturnOrigins` |
| S6 | `Checkout\Headless\HeadlessCheckoutService` (start / return / cancel), `PaymentHandlerRegistry` on `ContractFirstPaymentHandlerInterface`, `ReturnResolverRegistry`, `GraphQL\` glue (mapper, `PAYMENT_CHECKOUT`, result types, placeOrder guard), `UserBasketRemovalHandler`; graphql-storefront installed in the dev shop |
| S7 | `ContractOpeningService`, `UserBasketFactory`, `GuestUserResolver`; `AbstractAcpCheckoutService` default `createCheckout()` + `commitPaid()`; provider guide rewritten |

## Hand-over: the provider stories (P-Stripe, P-Mollie, P-PayPal — same branch name)

1. Handler: `implements ContractFirstPaymentHandlerInterface`; `processPayment()` reads basket, user, URLs and
   `metadata.basketId` from the `PaymentContext` (no `Registry::getSession()`), and puts `basketId` into the
   `ContractDraftCompletedEvent` context. Pending handlers decide what to do for `metadata.headless` / `channel = acp`
   (Stripe: Checkout Session with the client's `uiMode`; nothing for `acp`).
2. services.yaml: `- { name: oe.payment.return_resolver, provider: <name> }` on the return resolver;
   `$openAttemptFinder: '@…OpenAttemptFinderInterface'` on `EarlyOrderCreationHandler`; remove the module-local
   `SessionAdapterInterface`, `SessionWriterInterface`, `ContractServiceInterface`, `EventDispatcherInterface`
   definitions (payment-base owns them now — keeping them is harmless, deleting them is the clean-up).
3. GraphQL: `src/<Provider>/GraphQL/Controller/<Provider>Checkout.php` with `<provider>CheckoutStart / Return /
   Cancel`, `#[Right('PAYMENT_CHECKOUT')]`, over `HeadlessCheckoutServiceInterface`, returning payment-base's
   `Checkout*Result` types; own `graphql_namespace_mapper` for the controller namespace.
4. Webhooks: success handlers call `ContractCommitServiceInterface` with a `PaymentConfirmation` instead of skipping on
   `!isCommitted()`; Stripe adds `checkout.session.completed` to `WebhookEventCatalog` and consults its in-flight
   guard before `deleteNotFinishedOrder()` in `RetryCleanupService`.
5. ACP: `<Provider>CheckoutService extends AbstractAcpCheckoutService` with `paymentId()`, `providerName()`,
   `completePayment()` → `commitPaid()`; wire the four headless collaborators.
6. Proof: a Playwright spec that drives the mutations directly (no Twig) and finishes payment in the PSP's test UI;
   the existing Twig e2e suite unchanged.

## Phase 0 facts for whoever runs this next

- Dev shop: `oe_graphql_base` + `oe_graphql_storefront` active, `OEGQL_*` columns present. The shop's `composer.json`
  pins the payment-base path repo to `dev-b-7.4.x` (`options.versions`) because the checkout is on a feature branch.
- Integration tests: run with the shop's PHPUnit (`php vendor/bin/phpunit -c extensions/payment-base/tests/phpunit-integration.xml`);
  payment-base's own PHPUnit binary collides with the shop's error handler.
- Not in this sprint (documented in the S6 report): vouchers and the storefront's delivery-address choice on a user
  basket; guest/anonymous checkout; `ui_mode=custom`; the UCP REST profile.
