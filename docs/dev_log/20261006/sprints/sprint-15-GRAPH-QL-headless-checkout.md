# Sprint 15 — GRAPH-QL: headless checkout (GraphQL Storefront + MCP/ACP) on the contract-first flow

**Date:** 2026-10-06 · **Epic:** GRAPH-QL · **Branch:** `b-7.4.x-GRAPH-QL` in payment-base, stripe, paypal, mollie-payment
(all cut from `b-7.4.x`; payment-base lands first, the providers' CI installs payment-base from `b-7.4.x`).
**Status:** PLANNED — decision taken 2026-10-06: **Option B** of
[`../../20261002/reports/graphql-placed-order-and-payments.md`](../../20261002/reports/graphql-placed-order-and-payments.md)
(keep contract-first, expose it through provider mutations; core `placeOrder` is not used for our payments).
**Requirements:** [`../../20260903/sprints/_engeneering_requirements.md`](../../20260903/sprints/_engeneering_requirements.md)
apply unchanged (TDD-first, DevOps-first, proven through the client, SOLID, agnosticism, additive only).

## Ask

Make the shared checkout in payment-base usable **without a PHP session**, so that two API clients can drive it:

1. the OXID **GraphQL Storefront** (JWT identity, basket = `oxuserbaskets` row addressed by id), through three
   mutations per provider — `<provider>CheckoutStart`, `<provider>CheckoutReturn`, `<provider>CheckoutCancel`;
2. the **MCP/ACP/UCP** agentic-commerce layer that payment-base already ships in `src/Mcp/` (framework only, no
   provider implements `AcpCheckoutServiceInterface` today) — `create_checkout` / `complete_checkout` need exactly the
   same seams: a basket that is not in the session, a user that is not in the session, and a commit that does not
   depend on a browser return.

Stripe, PayPal and Mollie each add the thin GraphQL layer on top; admin, capture, refund, reconciliation and the Twig
checkout stay byte-identical.

## The flow we build (Option B, provider-agnostic)

```
basketSetPayment(basketId, <provider payment id>)                       core storefront mutation
<provider>CheckoutStart(basketId, confirmTermsAndConditions, returnUrl, cancelUrl, uiMode)
      → { contractId, orderNumber, redirectUrl | clientSecret, renderMode }
      = today's "Place Order": contract(DRAFT) → early order(NOT_FINISHED) → PENDING → PSP session
        basket loaded by id from oxuserbaskets, user from the JWT
shopper pays at the PSP; PSP sends the client to returnUrl?contract_id=…&contract_token=…&<provider ids>
<provider>CheckoutReturn(contractId, contractToken, <provider ids>)
      → { status, orderId, orderNumber }
      = today's checkoutSuccess(): ReturnResolution → CheckoutReturnResponder → COMMITTED → FULFILLED
<provider>CheckoutCancel(contractId, contractToken)
```

A `BeforePlaceOrder` subscriber refuses core `placeOrder` for baskets whose payment id belongs to a contract-first
provider, with a clear error naming the mutation to call instead.

## Where the session is today (from the 2026-10-02 report, verified)

| Seam | File | Replaced by |
|---|---|---|
| Basket from session | `src/Adapter/OxidShopOrderService.php:196-204`, `:257-265` (`basket_not_found`) | `CheckoutBasketProviderInterface` |
| User from `$basket->getBasketUser()` | `OxidShopOrderService::validateBasketAndUser()` | `CreateOrderRequest::$userId` is authoritative |
| `sess_challenge` duplicate guard | `OxidShopOrderService::sessionChallenge()`, `Validation/Guard/OxidSessionChallengeVerifier`, `Controller/SessionWriterInterface` | `CheckoutAttemptGuardInterface` backed by `oe_payments_idempotency` |
| Retry marker, payment-step skip, notices | `Checkout/OpenCheckoutAttemptRegistry` (`oepb_open_checkout_contract_id`), `Checkout/PaymentStepSkipGuard`, `Checkout/PreviousCheckoutAttemptCleaner`, `Checkout/SessionCheckoutNoticeRelocator` | `CheckoutContextInterface` (contract-backed implementation) |
| Session-scoped retire of previous attempt | `EventSystem/Handler/EarlyOrderCreationHandler::retirePreviousAttempt()` (`:109-120`) | retire by (user, basket) when no session |
| Commit only from the return leg | `Controller/CheckoutReturnResponder` is the only dispatcher of `PaymentAuthorizedEvent` in payment-base | `ContractCommitServiceInterface`, callable from return **and** webhook |

Already session-free and untouched: `ContractService::createContract($userId, $basket, …)` + `BasketSnapshot`,
`ReturnResolution` → `CheckoutReturnResponder::respond()` → `ContractCommitmentHandler`, `oe_payments_idempotency`,
capture/refund/cancel-auth/OXPAID reconciliation, the OPC `PaymentHandlerInterface` /
`PaymentContextInterface{getBasket, getUser, getPaymentMethodId, getReturnUrl, getCancelUrl, getMetadata}` /
`PaymentHandlerResult{contractId, clientSecret, metadata}` that all three providers already implement.

## Design (payment-base owns the seams, providers own the mutations)

| Piece | One job |
|---|---|
| `Checkout\Basket\CheckoutBasketProviderInterface` + `SessionBasketProvider` + `UserBasketProvider` | hand `OxidShopOrderService` an `oxBasket`: from `Registry::getSession()` (Twig/OPC) or from `oxuserbaskets` by id with ownership check against the user id (GraphQL/MCP). `UserBasketProvider` builds the basket the way graphql-storefront's `BasketInfrastructure` does and sets `sDeliveryAddressMD5`; it is the **only** place that knows `oxuserbaskets` |
| `Adapter\Request\CreateOrderRequest::$basketId` (nullable, additive) | `null` ⇒ session provider, else user-basket provider. `EarlyOrderCreationHandler` passes it from the event context key `basketId` |
| `Checkout\CheckoutContextInterface` + `SessionCheckoutContext` + `ContractCheckoutContext` | the per-attempt flags (`open_checkout_contract_id`, `payment_step_skipped`, `agb_confirmed`, `skip_addr_check`, provider ids) keyed by session **or** by contract id in `oe_payments_sessions`. `OpenCheckoutAttemptRegistry`, `PaymentStepSkipGuard`, `PreviousCheckoutAttemptCleaner` consume it. The `SessionAdapterInterface` binding moves into payment-base (one definition) — today `services.yaml:108-112` says the providers bind it |
| `Checkout\Guard\CheckoutAttemptGuardInterface` + `SessionChallengeAttemptGuard` + `IdempotentAttemptGuard` | "is this a second submission of the same attempt?" — core `sess_challenge`/`ORDEREXISTS` for Twig, `oe_payments_idempotency` keyed `(userId, basketId)` for headless. `OxidShopOrderService::refuseSecondSubmission()` asks the guard; `SessionWriterInterface::writeSessChallenge()` gets a `NullSessionWriter` for the headless path |
| `Service\ContractCommitServiceInterface` + `ContractCommitService` | "commit PENDING contract *x* if the provider says paid and amount+currency match the snapshot" — dispatches `PaymentAuthorizedEvent` exactly as `CheckoutReturnResponder` does today; idempotent (`already_processed` is a success). Called by the return leg **and** by provider webhook handlers (headless safety net when the return leg never arrives). `cleanupStaleContracts()` must consult the in-flight guard **before** deleting the NOT_FINISHED order |
| `Checkout\ReturnUrl\ReturnUrlPolicyInterface` + `AllowListReturnUrlPolicy` | validate client-supplied `returnUrl`/`cancelUrl` against configured storefront origins (module setting, empty = shop URL only). Open-redirect surface, solved once |
| `GraphQL\` (new, optional) | `Subscriber\RefusePlaceOrderForContractFirstPayments` (`BeforePlaceOrder`), `Permission\PaymentCheckoutPermissionProvider` (`PAYMENT_CHECKOUT` for `oxidcustomer`; `oxidanonymous` behind a setting), shared DataTypes `CheckoutStartResult{contractId, orderNumber, redirectUrl, clientSecret, renderMode}`, `CheckoutReturnResult{status, orderId, orderNumber, contractState}`, `CheckoutCancelResult`, and `Service\HeadlessCheckoutService` that runs start/return/cancel against the seams above so a provider mutation is ~20 lines. Wired by `services_graphql.yaml`, imported only when `oe_graphql_storefront` is active; `oxid-esales/graphql-storefront` in `require-dev` only |
| `EventSystem\Handler\UserBasketRemovalHandler` | on `ContractCommittedEvent` delete the `oxuserbaskets` row named in the contract metadata (`basketId`); no-op without it; never on cancel (shopper retries) — mirrors `BeforeBasketRemoveOnPlaceOrder` |
| `Mcp\Acp\AbstractAcpCheckoutService` | `createCheckout()` default implementation builds a user basket from `items` + `buyer` + `fulfillment_address` and calls the same `HeadlessCheckoutService::start()`; `completeCheckout()` calls `ContractCommitService` after the provider's `completePayment()` — so GraphQL and MCP share one code path |
| `README.md` | make `src/GraphQL/ # Headless API support` true; document the mutation contract for storefront developers |

Provider modules (same branch name, separate repos) add only:

| Module | Adds |
|---|---|
| **stripe** | `src/Stripe/GraphQL/Controller/StripeCheckout` with `stripeCheckoutStart/Return/Cancel`, reusing `CheckoutSessionService` (already snapshot-based), `StripeReturnResolver`, `StripeSuccessUrlBuildEvent`/`StripeCancelUrlBuildEvent` listeners that inject the client URLs and drop `force_sid`; `uiMode` ∈ `hosted` / `embedded` / `custom`; `checkout.session.completed` (+ `payment_intent.succeeded`) webhook handlers call `ContractCommitService` instead of skipping on `!isCommitted()`; `checkout.session.completed` added to `WebhookEventCatalog`; `StripePaymentHandler::createEarlyOrderAndTransition()` stops reading the session |
| **mollie-payment** | `mollieCheckoutStart/Return/Cancel` over `MollieReturnResolver` + `HandlesMollieCheckoutReturn`; Mollie webhook (`paid`/`authorized`) calls `ContractCommitService`; delete the module-local `Mollie/Adapter/OxidShopOrderService.php` and `OxidSessionAdapter.php` copies that shadow payment-base's single implementation |
| **paypal** | `paypalCheckoutStart/Return/Cancel` over `PayPalReturnResolver`/`PayPalPaymentHandler`; `returnUrl`/`cancelUrl` from the client through `ReturnUrlPolicy`; PayPal `CHECKOUT.ORDER.APPROVED`/`PAYMENT.CAPTURE.COMPLETED` webhooks call `ContractCommitService` |

## Stories (payment-base, in dependency order)

| # | Story | Red (first) | Green | Proof |
|---|---|---|---|---|
| S1 | **Basket provider.** `CheckoutBasketProviderInterface`, `SessionBasketProvider`, `UserBasketProvider`, `CreateOrderRequest::$basketId` | `OxidShopOrderServiceTest`: with `basketId` set, no `Registry::getSession()` call; unknown basket ⇒ `basket_not_found`; foreign basket ⇒ `basket_forbidden`. `UserBasketProviderTest` against `oxuserbaskets` fixtures: totals, voucher, delivery set, payment equal the storefront's basket | provider classes, order service asks the provider, handler passes `basketId` | Integration: `oxuserbaskets` fixture → `EarlyOrderCreationHandler` → NOT_FINISHED order with the same `OXTOTALORDERSUM` the storefront would show |
| S2 | **Checkout context.** `CheckoutContextInterface`, `SessionCheckoutContext`, `ContractCheckoutContext`; move the `SessionAdapterInterface` binding into payment-base | `OpenCheckoutAttemptRegistryTest`, `PaymentStepSkipGuardTest`, `PreviousCheckoutAttemptCleanerTest` run once per implementation (data provider); contract-backed values survive a new PHP process | implementations + services.yaml; providers' duplicate `SessionAdapterInterface` definitions removed (their test counts unchanged) | Twig e2e suites of stripe and mollie green with payment-base from this branch |
| S3 | **Attempt guard.** `CheckoutAttemptGuardInterface`, `SessionChallengeAttemptGuard`, `IdempotentAttemptGuard`, `NullSessionWriter`; `retirePreviousAttempt()` by (user, basket) when headless | `OxidShopOrderServiceTest::secondSubmissionIsRefused` for both guards; `EarlyOrderCreationHandlerTest`: second `CheckoutStart` on the same basket retires the first NOT_FINISHED order and reuses nothing from the session | guards + wiring | MOL-18 Twig regression suite still green (one order per attempt) |
| S4 | **Contract commit service.** `ContractCommitServiceInterface`, `ContractCommitService`; `CheckoutReturnResponder` delegates to it; stale cleanup checks in-flight guard first | `ContractCommitServiceTest`: PENDING + matching amount ⇒ COMMITTED + `PaymentAuthorizedEvent` once; mismatch ⇒ refused + logged; COMMITTED ⇒ `already_processed`; `CleanupStaleContractsTest`: paid in-flight session never cancelled | service, responder refactor, cleanup ordering | Integration: fake "paid" webhook on a PENDING contract ⇒ FULFILLED order with no return leg |
| S5 | **Return-URL policy.** `ReturnUrlPolicyInterface`, `AllowListReturnUrlPolicy`, module setting | `AllowListReturnUrlPolicyTest`: foreign origin refused, configured origin accepted, relative path refused, empty list ⇒ shop URL only | policy + setting + translations | — (unit is the proof; no UI) |
| S6 | **GraphQL glue.** `src/GraphQL/*`, `services_graphql.yaml`, `HeadlessCheckoutService`, `BeforePlaceOrder` subscriber, permission provider, `UserBasketRemovalHandler`; install `oxid-esales/graphql-storefront:dev-b-7.4.x` as require-dev; README | `HeadlessCheckoutServiceTest` (start/return/cancel over fakes), `RefusePlaceOrderForContractFirstPaymentsTest` (`oxidpayadvance` passes, provider id refused with message), `UserBasketRemovalHandlerTest` (removed on commit, kept on cancel) | code + yaml + README | Integration against graphql-storefront fixtures: `token` → `basketCreate` → `basketSetPayment` → start → fake return → `order(id)` returns the order |
| S7 | **ACP on the same path.** `AbstractAcpCheckoutService::createCheckout()` default + `completeCheckout()` through `ContractCommitService` | `AbstractAcpCheckoutServiceTest` with a fake provider: `create_checkout` ⇒ PENDING contract + NOT_FINISHED order, `complete_checkout` ⇒ COMMITTED; ACP status mapping unchanged | code + `src/Mcp/docs` update | Integration: MCP JSON-RPC `tools/call create_checkout` → `complete_checkout` end-to-end with the fake |
| S8 | **Gates, consumers, hand-over.** phpcs / phpstan / phpmd / Unit + Integration green; stripe, mollie, paypal, opalreturns, one-page-checkout test counts identical before/after with payment-base from this branch; push; providers start | — | — | CI green on all five consumers |

Provider stories (one sprint doc each, same branch name, start after S6 is pushed): **P-Stripe** (mutations, URL
listeners, webhook commit, catalog), **P-Mollie** (mutations, webhook commit, delete local adapter copies),
**P-PayPal** (mutations, webhook commit). Each is proven by a Playwright spec that drives the mutations directly
(no Twig) and finishes payment in the PSP's test UI, plus the existing Twig e2e suite unchanged.

## Phasing

| Phase | Scope | Outcome |
|---|---|---|
| 0 | `composer require --dev oxid-esales/graphql-storefront:dev-b-7.4.x` in the dev shop, activate `oe_graphql_base` + `oe_graphql_storefront`, run the documented `placeOrder` with `oxidpayadvance` | Baseline: headless core works on 7.4 (only `graphql-base` is installed today) |
| 1 | S1–S3 | Session dependency removed from the shared checkout; Twig unchanged |
| 2 | S4–S6, then P-Stripe (registered users, hosted + embedded) | First headless payment end-to-end |
| 3 | S7, P-Mollie, P-PayPal; stale-cleanup ordering fix shipped | All three providers headless and MCP-ready; safe against lost return legs |
| 4 | `ui_mode=custom` (Stripe), anonymous/guest checkout (`oxidanonymous` + PSP-collected address), storefront developer docs | Full parity |

## Decisions and non-goals

- **Core `placeOrder` is not used** for contract-first payments (Option B). The storefront client must call the
  provider mutations; the `BeforePlaceOrder` guard makes a wrong call fail loudly, not silently.
- **No second checkout model.** Nothing in the GraphQL/MCP path may create an order outside
  `EarlyOrderCreationHandler` → `OxidShopOrderService`, or commit outside `ContractCommitService`.
- **Agnosticism.** payment-base code and the GraphQL glue carry no provider literal; a provider is known only by the
  payment ids it registers. A shop with no PSP module installed must boot with `services_graphql.yaml` loaded.
- **Additive only.** Every new constructor argument is nullable with the session implementation as default; Twig and
  OPC behaviour is byte-identical; consumer test counts must match before/after.
- **Guest checkout, `ui_mode=custom`, express flows and the UCP REST profile** are phase 4 / later sprints. Not here.
- **Not in scope:** the `one-page-checkout` module's `Controller/GraphQL/OnePageController.php` stub. It gets replaced
  by the provider mutations, not wired.

## Risks

- **Basket parity** between the storefront's `basketPayments` basket and ours: build it once, in `UserBasketProvider`,
  by reusing graphql-storefront's `BasketInfrastructure`/`BasketRelationService`; never re-derive totals by hand.
- **Interface bindings.** `SessionAdapterInterface` is bound by the providers; mollie-payment still carries its own
  `OxidShopOrderService`/`OxidSessionAdapter`. S2 and P-Mollie clean this up **before** a second implementation
  exists, or the headless path is wired against the wrong one.
- **Lost return leg** (closed tab, killed app) with a paid PSP session: S4 is the fix; until it ships, headless
  must not go to production.
- **JWT vs PSP session lifetime.** `contract_token` is the credential of the return mutation, not the JWT, so an
  expired anonymous token does not strand a paid session.
- **graphql-storefront install.** The 2026-04-22 PayPal dev log notes a `reflection-docblock` pin fight when
  installing graphql-base; expect the same for storefront (phase 0).

## Sources

- [`20261002/reports/graphql-placed-order-and-payments.md`](../../20261002/reports/graphql-placed-order-and-payments.md) (payment-base view)
- `stripe/docs/oe_payments_docs/daniil_dev_log/20261001/reports/01-headless-stripe-graphql-feasibility.md` (provider view)
- `src/Mcp/docs/01-developer-guide.md`, `03-building-provider-modules.md` (ACP/UCP contract and state mapping)
- OXID GraphQL docs v13: `consuming/PlaceOrder`, `thirdpartypayments/*`, `events/BeforePlaceOrder`;
  `OXID-eSales/graphql-storefront@b-7.4.x` `src/Basket/Service/PlaceOrder.php`, `src/Basket/Infrastructure/Basket.php`
