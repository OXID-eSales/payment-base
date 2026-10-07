# Research — GraphQL `placeOrder` and payments: what payment-base has to offer a headless shop

**Date:** 2026-10-02 · **Repo:** `extensions/payment-base` (`b-7.4.x`) · **Author:** Daniil
**Type:** analysis / options report, no code changed
**Builds on:** `stripe/docs/oe_payments_docs/daniil_dev_log/20261001/reports/01-headless-stripe-graphql-feasibility.md`
(the provider-side view). This report takes the **payment-base** view: which parts of the shared checkout are
session-bound, what the GraphQL Storefront expects instead, and what the base module must provide so that *any*
provider module (Stripe, Mollie, future ones) can expose a headless checkout without re-implementing the contract flow.

---

> **Implemented (2026-10-06/07):** Option B, Sprint 15 + the three provider stories. How a client chooses the payment
> method and the shared mutations: [`docs/graphql-headless-checkout.md`](../../../graphql-headless-checkout.md).

## 0. Short answer

**payment-base is the place where headless support has to land, and today it has none.** The provider-facing code is
already stateless (return validation by HMAC contract token, webhooks, capture/refund, reconciliation). The shared
checkout core is not:

1. `OxidShopOrderService::createOrder()` reads the basket from the PHP session
   (`src/Adapter/OxidShopOrderService.php:196-204`) and refuses with `basket_not_found` otherwise (`:257-265`);
2. duplicate-submission protection is core's `sess_challenge` (`:207-212`, `OxidSessionChallengeVerifier`);
3. retry handling, payment-step skipping and checkout notices are keyed on `$_SESSION`
   (`OpenCheckoutAttemptRegistry`, `PaymentStepSkipGuard`, `PreviousCheckoutAttemptCleaner`, `SessionCheckoutNoticeRelocator`);
4. the only path that moves a contract PENDING → COMMITTED is the browser return leg through
   `CheckoutReturnResponder`, which is the one place in payment-base that dispatches `PaymentAuthorizedEvent`.

The GraphQL Storefront has **no session**: identity is a JWT, the basket is an `oxuserbaskets` row addressed by id,
and `placeOrder(basketId)` builds an `oxBasket` from that row and calls `finalizeOrder()` itself. Our contract-first
flow ("Place Order" creates a contract and an early NOT_FINISHED order, the real order is fulfilled later) does not
fit core `placeOrder` as-is. The work is three seams in payment-base plus a webhook-driven commit; providers then
add a thin GraphQL layer each.

Also: `README.md:125` advertises `src/GraphQL/ # Headless API support`. The directory does not exist. Neither
`composer.json` nor `services.yaml` mention GraphQL. Only `graphql-base` is installed in the dev shop
(`vendor/oxid-esales/`), not `graphql-storefront`.

---

## 1. What core GraphQL `placeOrder` does (for reference)

```
token(username, password)                       → JWT
basketCreate / basketAddItem                    → oxuserbaskets + oxuserbasketitems, basket id
basketSetDeliveryMethod / basketSetPayment
placeOrder(basketId, confirmTermsAndConditions, remark) → { id, orderNumber }
```

`PlaceOrder::placeOrder()` (graphql-storefront `b-7.4.x`) dispatches `BeforePlaceOrder(basketId)`, validates AGB,
orderable items, delivery and payment availability, then `BasketInfrastructure::placeOrder()` builds an `oxBasket`
from the user basket, sets `$_POST['sDeliveryAddressMD5']` so core's address validation passes, and calls
`oxOrder::finalizeOrder($basket, $user)`. Afterwards it dispatches `BeforeBasketRemoveOnPlaceOrder` and deletes the
user basket unless a subscriber keeps it.

The documented third-party-payments contract expects a payment module to add its own approval query
(`3rdPartyStandardApprovalProcess` / `3rdPartyExpressApprovalProcess`), persist provider state "in some suitable
place" (PayPal: extra columns on `oxuserbaskets`), and finish the job in a `BeforePlaceOrder` subscriber. Guest
checkout exists only as the anonymous token (group `oxidanonymous`), which needs explicit permissions granted via
`PermissionProviderInterface`. Best practice from the OXID docs: keep GraphQL an **optional** dependency (separate
services file, loaded only when the GraphQL modules are active).

---

## 2. Where payment-base touches the session today

Counted with `grep -rn "getSession()\|SessionAdapterInterface\|\$_SESSION" src` (excluding `src/Mcp`):

| File | Hits | Role in checkout |
|---|---|---|
| `src/Adapter/OxidSessionAdapter.php` | 8 | The `SessionAdapterInterface` implementation (`getSessionId`, `getBasket`, `setVariable`, …) |
| `src/Checkout/PaymentStepSkipGuard.php` | 5 | Session flag deciding whether the payment step may be skipped |
| `src/Checkout/SingleShippingAssigner.php` / `SinglePaymentAssigner.php` | 3 + 3 | Mirror the single available delivery set / payment into the session basket |
| `src/Adapter/OxidShopOrderService.php` | 3 | `sessionBasket()` and `sessionChallenge()` seams |
| `src/Validation/Guard/OxidSessionChallengeVerifier.php` | 2 | Wraps `Registry::getSession()->checkSessionChallenge()` (stoken) |
| `src/Eshop/Application/Controller/OrderController.php` | 2 | Twig controller extension |
| `src/Checkout/SessionCheckoutNoticeRelocator.php` | 2 | Moves checkout notices between session steps |
| `src/Checkout/PreviousCheckoutAttemptCleaner.php` | 2 | Retires the attempt the same session left open |
| `src/Checkout/OpenCheckoutAttemptRegistry.php` | 2 | Session key `oepb_open_checkout_contract_id` (STRP-171) |
| `src/Controller/SessionWriterInterface.php` | 1 | `writeSessChallenge()` after the return leg |
| `src/Admin/PaymentAdminController.php` | 1 | Admin only, irrelevant here |

What is **already** session-free and stays as is:

- Contract creation: `ContractService::createContract($userId, $basket, …)` takes any `Basket` object and persists a
  `BasketSnapshot` in `oe_payments_contract.OXBASKETDATA`.
- Return validation and commit: `CheckoutReturnResponder::respond()` takes the provider's `ReturnResolution`
  (contract id, contract token, provider ids), dispatches `PaymentAuthorizedEvent`, and `ContractCommitmentHandler`
  moves the contract to COMMITTED. Only the surrounding Twig bits (`SessionWriterInterface`, `thankyou` redirect)
  are session-bound.
- Idempotency: `oe_payments_idempotency` (`DoctrineIdempotencyRepository`, migration `Version20251031140200`) and
  rate limiting already exist and are keyed on request/contract, not on session.
- Capture, refund, cancel-authorization, OXPAID reconciliation, admin tab.

---

## 3. How the early order is created today

`ContractDraftCompletedEvent` → `EarlyOrderCreationHandler::handle()`:

1. `retirePreviousAttempt()` — scoped to the *session* on purpose (`:109-120`): it takes the previous contract id
   from `OpenCheckoutAttemptRegistry` and deletes that NOT_FINISHED order.
2. `createOrder()` builds a `CreateOrderRequest(sessionId, userId, paymentId, …)` (`:149-170`), where `sessionId`
   falls back to `'contract_' . $contract->getId()` when the context has none (`:156`) — a hint that the request
   shape already tolerates a missing session.
3. `OxidShopOrderService::createOrder()` → `validateBasketAndUser()` → **`Registry::getSession()->getBasket()`**;
   user is taken from `$basket->getBasketUser()`, *not* from `$request->userId`.
4. `finalizeAndValidateOrder()` calls `$order->finalizeOrder($basket, $user, false)` (`:137`). Core answers
   `ORDER_STATE_ORDEREXISTS` when an order for the current `sess_challenge` already exists; `refuseSecondSubmission()`
   maps that (MOL-18, `:146-160`).
5. Contract → NOT_FINISHED → PENDING; `OrderCreatedEvent` dispatched.

So the headless gap is exactly steps 1, 3 and 4: *which basket*, *which user*, *which duplicate guard*.

---

## 4. Two ways to meet the GraphQL Storefront

### Option A — bend to core `placeOrder` (PayPal pattern)

Provider approval query creates the PSP session from the `oxuserbasket`, stores provider ids, shopper pays, then
core `placeOrder` runs `finalizeOrder` and a `BeforePlaceOrder` subscriber verifies the payment. Clean against the
OXID docs, but it **bypasses the contract lifecycle**: no early order, no order number before redirect, no
DRAFT → NOT_FINISHED → PENDING → COMMITTED chain, so the admin tab, capture-mode semantics, reconciliation and the
`OrderActionDispatcher` would all need a second model. Rejected for the same reason as in the Stripe report.

### Option B — keep contract-first, expose it through provider mutations (recommended)

```
basketSetPayment(basketId, <provider payment id>)
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

Core `placeOrder` is **not** called for contract-first payments; a `BeforePlaceOrder` subscriber refuses it with a
clear error when the basket's payment id belongs to a contract-first provider. Everything from `PaymentAuthorizedEvent`
onwards is unchanged. The OPC `PaymentHandlerInterface` / `PaymentHandlerResult{contractId, clientSecret,
redirectUrl, renderMode}` already prove this API shape works for both providers.

---

## 5. Work items in payment-base

Ordered by dependency. All behind interfaces; Twig behaviour must stay byte-identical (regression: the full
Stripe + Mollie e2e suites).

1. **Basket provider for the order service.** Replace the `sessionBasket()` seam in `OxidShopOrderService` with a
   `CheckoutBasketProviderInterface`:
   - `SessionBasketProvider` — today's `Registry::getSession()->getBasket()` (Twig, OPC);
   - `UserBasketProvider` — loads `oxuserbaskets` by id, checks ownership against `CreateOrderRequest::$userId`,
     builds the `oxBasket` the way graphql-storefront's `BasketInfrastructure` does (prices, vouchers, delivery set,
     payment must match what `basketPayments` showed), and sets `sDeliveryAddressMD5` instead of relying on the
     providers' `skip_addr_check` session flags.
   `CreateOrderRequest` gains an optional `basketId`; `EarlyOrderCreationHandler` passes it through from the event
   context. The user must then come from `$request->userId`, not from `$basket->getBasketUser()` alone.
2. **Checkout context instead of `$_SESSION` flags.** `SessionAdapterInterface` already exists; add a
   contract-backed implementation (`oe_payments_sessions` or contract metadata keyed by contract id) for
   `oepb_open_checkout_contract_id`, the payment-step-skip flag, AGB consent and the providers' per-checkout ids.
   `OpenCheckoutAttemptRegistry`, `PaymentStepSkipGuard`, `PreviousCheckoutAttemptCleaner` consume the interface
   they already have; `services.yaml:108-112` notes the interface id is bound by the provider modules — that binding
   has to move to payment-base (one definition) before a second implementation makes sense.
3. **Duplicate-submission guard without `sess_challenge`.** `OxidSessionChallengeVerifier` and
   `refuseSecondSubmission()` rely on core's session challenge. Headless needs the same guarantee from
   `oe_payments_idempotency` keyed on `(userId, basketId)` or the contract id; `SessionWriterInterface::writeSessChallenge()`
   becomes a no-op implementation in the GraphQL path. `EarlyOrderCreationHandler::retirePreviousAttempt()` must
   then retire by *user + basket* instead of by session (the comment at `:113` says session scope is deliberate; the
   headless scope needs its own rule, e.g. "one open contract per user basket").
4. **Webhook-driven commit (provider-agnostic hook).** Today `PaymentAuthorizedEvent` is raised only by
   `CheckoutReturnResponder` (plus Stripe's `StripePaymentStatusHandler`). For a headless client the return leg is
   not guaranteed (closed tab, killed app), and the Stripe report documents a *paid session, cancelled contract,
   no order* hazard when stale cleanup runs first. payment-base should offer one service — "commit PENDING
   contract X if provider says paid and amount matches" — that both the return leg and the providers' webhook
   handlers call, and the stale-cleanup must consult the in-flight guard **before** deleting the NOT_FINISHED order.
5. **Return-URL policy.** Providers will accept client-supplied `returnUrl`/`cancelUrl`. payment-base should hold the
   allow-list of storefront origins and the validator; this is an open-redirect surface and should not be solved
   twice.
6. **GraphQL glue as optional dependency.** `src/GraphQL/` (make the README true): the `BeforePlaceOrder` guard
   subscriber (refuse core `placeOrder` for contract-first payment ids), a `PermissionProviderInterface` base
   granting `PAYMENT_CHECKOUT` to `oxidcustomer` (+ `oxidanonymous` if guest checkout is wanted), shared DataTypes
   (`CheckoutStartResult`, `CheckoutReturnResult`), and a `services_graphql.yaml` imported only when
   `oe_graphql_storefront` is active. `oxid-esales/graphql-storefront` goes to `require-dev` only.
7. **User-basket lifecycle.** Core deletes the user basket in `placeOrder`; we must delete it on **commit** (not on
   cancel, so the shopper can retry), mirroring `BeforeBasketRemoveOnPlaceOrder` semantics. Natural home: a handler
   on `ContractCommittedEvent` that no-ops when the contract has no `basketId`.
8. **Guest checkout (later).** Anonymous JWT + PSP-collected address: the commit path creates/updates `oxuser` and
   order addresses from provider data — the headless analogue of PayPal express filling the basket in
   `BeforePlaceOrder`. Not needed for a first PoC with registered users.

Provider modules then add: three mutations, a `ReturnResolution` factory reused from today's return controller, URL
build listeners that inject the client URLs, and webhook handlers that call item 4. Nothing in admin, capture, refund
or reconciliation changes.

---

## 6. Risks and open questions

- **Basket parity.** The early order's amount and the PSP amount must come from the same `oxBasket` the storefront
  used for `basketPayments`. Building it twice (storefront infrastructure vs our provider) is the main correctness
  risk; prefer depending on graphql-storefront's `BasketInfrastructure` in the GraphQL path rather than re-deriving.
- **Interface binding.** `SessionAdapterInterface` and (historically) `ShopOrderServiceInterface` were bound by the
  provider modules; `services.yaml:91` says the order service is now one implementation in payment-base, but
  `mollie-payment/src/Mollie/Adapter/OxidShopOrderService.php` and `OxidSessionAdapter.php` still exist. Clean this
  up before adding a second implementation, or the GraphQL path will be wired against the wrong one.
- **Two frontends, one base.** Every future Twig fix in a provider's `OrderController` must land in the shared
  service, not the controller — this is already the pressure the OPC `PaymentHandler` created.
- **Anonymous JWT vs PSP session lifetime.** The return mutation must work after the JWT expires; it can, because
  `contract_token` is the credential, not the JWT.
- **Adjacent, not in scope:** `src/Mcp/` (ACP/UCP agentic commerce) is the only API-shaped checkout in the codebase
  and shares the "basket by id, no session" requirement. Items 1–3 would serve it too; the one-page-checkout
  `Controller/GraphQL/OnePageController.php` stub has the same TODO ("Implement based on OXID session management").

---

## 7. Suggested phasing

| Phase | Scope | Outcome |
|---|---|---|
| 0 | Install/activate `graphql-storefront` in the dev shop; run documented `placeOrder` with `oxidpayadvance` | Baseline that headless core works on 7.4 |
| 1 | payment-base items 1–3 behind interfaces, Twig unchanged; fix README `src/GraphQL/` claim | Session dependency removed from the shared checkout |
| 2 | payment-base items 4–6; first provider (Stripe) mutations, registered users | First headless payment end-to-end |
| 3 | Item 7 + stale-cleanup ordering fix; second provider (Mollie) mutations | Both providers headless; safe against lost return legs |
| 4 | Guest checkout, storefront developer docs | Full parity |

---

## 8. Sources

- OXID GraphQL docs v13: `consuming/PlaceOrder`, `thirdpartypayments/{introduction,standard_checkout,express_checkout,best_practices}`,
  `events/{BeforePlaceOrder,BeforeBasketPayments}`
- `OXID-eSales/graphql-storefront@b-7.4.x`: `src/Basket/Service/PlaceOrder.php`, `src/Basket/Infrastructure/Basket.php`
- payment-base: `src/Adapter/OxidShopOrderService.php`, `src/Adapter/Request/CreateOrderRequest.php`,
  `src/Adapter/SessionAdapterInterface.php`, `src/EventSystem/Handler/EarlyOrderCreationHandler.php`,
  `src/EventSystem/Handler/ContractCommitmentHandler.php`, `src/Controller/CheckoutReturnResponder.php`,
  `src/Checkout/OpenCheckoutAttemptRegistry.php`, `src/Checkout/PaymentStepSkipGuard.php`,
  `src/Validation/Guard/OxidSessionChallengeVerifier.php`, `services.yaml:91-112`, `README.md:125`
- Stripe-side analysis: `stripe/docs/oe_payments_docs/daniil_dev_log/20261001/reports/01-headless-stripe-graphql-feasibility.md`
