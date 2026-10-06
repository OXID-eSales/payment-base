# Sprint 15 / S3 — One order per headless checkout attempt (DONE 2026-10-06)

**Sprint:** [sprint-15-GRAPH-QL-headless-checkout.md](../sprints/sprint-15-GRAPH-QL-headless-checkout.md) · **Branch:** `b-7.4.x-GRAPH-QL`

## The problem

core's own double-click guard is `sess_challenge`: the order page writes it once, `Order::finalizeOrder()` uses it as
the order id and answers `ORDEREXISTS` for a second submission (MOL-18 made the service honour that). A headless
request has no session, so `sess_challenge` is null, core mints a fresh id per call and two concurrent `CheckoutStart`
calls for one basket would create two NOT_FINISHED orders.

## What changed

| Piece | Job |
|---|---|
| `Checkout\Guard\CheckoutAttemptGuardInterface` | `claim(request)` (throws `order_exists`), `complete(request, orderId)`, `release(request)`; `ERROR_ORDER_EXISTS` is the same code the service already answers for core's ORDEREXISTS |
| `Checkout\Guard\IdempotentAttemptGuard` | on `oe_payments_idempotency` (table from 2025-10-31, first consumer): key `order_create:<userId>:<basketId>`, in-flight record for a 2-minute window, refusal names the order once there is one, release expires the record, after the window a new attempt is allowed. Requests without `basketId` are left to core |
| `OxidShopOrderService::createOrder()` | claims **before** the basket is loaded, completes with the order id, releases on any failure (optional ctor arg, no guard = today's behaviour) |
| `EarlyOrderCreationHandler` | stamps `basket_id` into the contract metadata; `retirePreviousAttempt()` asks the registry first and, for a headless attempt with nothing there, the repository: the open contract for the same user basket. Never itself |
| `ContractRepositoryInterface::findOpenByUserAndBasketId()` (+ Doctrine) | newest contract of the user in `not_finished` / `pending` whose metadata `basket_id` matches (`JSON_EXTRACT`); authorized, committed, terminal never qualify |
| `CheckoutReturnResponder` | optional `HeadlessCheckoutScopeInterface`; with an active scope `writeSessChallenge()` is skipped (no session, no thank-you page) |
| `services.yaml` | `IdempotencyRepositoryInterface` → `DoctrineIdempotencyRepository` (was unwired), guard bound, service gets `$attemptGuard` |

## Red → green

| Test | Covers |
|---|---|
| `Unit\Checkout\Guard\IdempotentAttemptGuardTest` (9) | session request not recorded; first claim; second refused (`order_id` null in flight); complete names the order and still refuses; release lets the next claim through; window elapses; other baskets/users independent; error code equality with the service |
| `Unit\Adapter\OxidShopOrderServiceTest` (+5) | claim → complete order; refusal creates nothing and never loads the basket; release on finalize failure and on missing basket; no guard = as before |
| `Unit\EventSystem\Handler\EarlyOrderCreationHandlerRetryCleanupTest` (+6) | basket_id stamped (headless) / absent (session); repository fallback retires the open one; registry wins; session attempt never asks the repository; own contract never retired |
| `Unit\Controller\CheckoutReturnResponderTest` (+2) | headless return writes no `sess_challenge`; session return still does |
| `Integration\Adapter\OxidShopOrderServiceHeadlessDoubleSubmitTest` | real shop, no session: second submission refused as `order_exists` naming the first order, one `oxorder` row, idempotency record `completed` with the order id |
| `Integration\Repository\DoctrineContractRepositoryOpenByBasketTest` | newest open one for the basket; authorized / cancelled / other basket / other user ⇒ null |

## Gates

- Unit **1455** green (6 pre-existing skips) · Integration **142** green (1 pre-existing skip)
- phpcs clean · PHPStan level max No errors · phpmd clean

## Notes

- The guard's window (2 min) is a constant; make it a setting only if a real client needs it.
- `DoctrineIdempotencyRepository` writes through the Doctrine connection; in integration tests that connection is not the one `IntegrationTestCase` rolls back, so the double-submit test deletes its record in `tearDown()`.
- `IdempotencyRecord::isExpired()` uses the wall clock; the guard compares `getExpiresAt()` against its own `now()` seam so tests can move time.
- `ContractRepositoryInterface` gained a method: the Doctrine class is the only implementation (mocks adapt), so this is additive in practice.
