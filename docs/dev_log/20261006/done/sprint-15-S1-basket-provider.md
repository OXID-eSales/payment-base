# Sprint 15 / S1 — Basket provider for the shop order service (DONE 2026-10-06)

**Sprint:** [sprint-15-GRAPH-QL-headless-checkout.md](../sprints/sprint-15-GRAPH-QL-headless-checkout.md) · **Branch:** `b-7.4.x-GRAPH-QL` · **Commit:** `874fdca`

## What changed

`OxidShopOrderService` no longer knows where baskets live. It asks a `CheckoutBasketProviderInterface`
(constructor argument, optional, session provider as default so consumers whose services.yaml predates this keep
byte-identical behaviour).

| Piece | Job |
|---|---|
| `Checkout\Basket\CheckoutBasketProviderInterface` | `basketFor(CreateOrderRequest): ?Basket` |
| `Checkout\Basket\SessionBasketProvider` | the old `sessionBasket()` seam, moved: `Registry::getSession()->getBasket()` |
| `Checkout\Basket\UserBasketProvider` | `oxuserbaskets` row by id → ownership check against `userId` (`basket_forbidden`) → shop `Basket` built like graphql-storefront's `BasketInfrastructure` (user, items incl. sel/pers params, payment from the request, delivery set from `OEGQL_DELIVERYMETHODID` when present else `DeliverySetList::getDeliverySetData()`, calculate) → delivery-address hash restored |
| `Checkout\Basket\CheckoutBasketRouter` | `basketId === null` ⇒ session provider, else user-basket provider; **no fallback** in either direction |
| `CreateOrderRequest::$basketId` | new, nullable, last positional argument |
| `EarlyOrderCreationHandler` | passes `basketId` from the event context (`''`/missing ⇒ null) |
| `services.yaml` | interface → router; providers; `DeliveryAddressHashService` wired by concrete class (Stripe binds the interface id) |

### Defect found and fixed on the way

`DeliveryAddressHashService::restoreHashForValidation()` wrote `$_REQUEST` only. core's
`Request::getRequestParameter()` reads `$_POST`, then `$_GET` — never `$_REQUEST` — so
`Order::validateDeliveryAddress()` never saw the restored hash. The Twig checkout never noticed because the order
page posts the hash itself; the first caller with no posted form (this provider) got
`ORDER_STATE_INVALIDDELADDRESSCHANGED` (the integration test caught it). It now writes `$_POST` too; pinned by
`DeliveryAddressHashServiceTest`.

## Red → green

| Test | Red because | Green |
|---|---|---|
| `Unit\Checkout\Basket\CheckoutBasketRouterTest` (4) | interface missing | routes by `basketId`, never falls back |
| `Unit\Checkout\Basket\UserBasketProviderTest` (8) | class missing | ownership, items/payment/shipping/calculate order, storefront delivery set wins, hash restored, unknown user refused |
| `Unit\Adapter\OxidShopOrderServiceTest` (+1, 1 adapted) | ctor had no provider | asks the provider with the request; `basket_not_found` without basket |
| `Unit\EventSystem\Handler\EarlyOrderCreationHandlerTest` (+2) | no `basketId` on the request | passes it; null when absent |
| `Unit\Service\DeliveryAddressHashServiceTest` (3, new) | wrote `$_REQUEST` only | writes `$_POST` |
| `Integration\Checkout\Basket\UserBasketProviderTest` (2) | — | same brutto as a session-built basket; order finalized from the row with **nothing in the session** |

## Gates

- Unit: **1410** green (6 pre-existing skips) — was 1392 before the sprint
- Integration (shop PHPUnit, `tests/phpunit-integration.xml`): **135** green (1 pre-existing skip)
- phpcs 353 files clean · PHPStan level max **No errors** (after `clear-result-cache`; the Liskov rule now allows `UserBasket`/`UserBasketItem` like the other OXID models) · phpmd clean

## Consumers

- Stripe's standalone unit suite does not load in this dev shop at all (`OxidEsales\Payments\Mollie\Controller\PaymentController_parent` not found while building the suite — Mollie's virtual class is not generated here). Pre-existing, unrelated to S1; S8 re-checks consumer counts on CI.
- payment-base's own integration suite exercises the Twig path (`OxidShopOrderServiceSecondSubmissionTest`, `…ShippingAddressTest`, `FullDataPersistenceFlowTest`) unchanged: green.

## Notes for S2+

- graphql-storefront is **not** installed; `OEGQL_DELIVERYMETHODID` handling is unit-tested with a fake row and will be integration-tested in S6 once the storefront is in `require-dev`.
- Vouchers on a user basket (storefront keeps them in `oxvouchers.oegql_basketid`) are not applied yet — S6 scope, when the real storefront schema is available.
- `UserBasketProvider` sets the hash through the service; it does not touch superglobals itself.
