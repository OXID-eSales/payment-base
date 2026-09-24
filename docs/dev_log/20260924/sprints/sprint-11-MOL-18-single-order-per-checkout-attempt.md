# Sprint 11 — MOL-18: one order per checkout attempt (payment-base half)

**Date:** 2026-09-24 · **Branch:** `b-7.4.x-MOL-18-single-order-per-checkout-attempt` · **Status:** DONE, pushed, not merged.
Twin plan and full report live in mollie-payment: `docs/dev_day_log/20260923/sprints/MOL-18-…md`,
`docs/dev_day_log/20260924/reports/MOL-18-…md`.

## What changed here

1. `3e4e690` **No phantom order on `ORDER_STATE_ORDEREXISTS`.** `OxidShopOrderService::createOrder()`
   throws `ShopOrderException` (`order_exists`) instead of saving the never-loaded `Order`. Seams
   `newOrder()` / `sessionBasket()` / `sessionChallenge()`; unit tests through them; fixture builders
   extracted to `tests/Integration/Support/CheckoutFixture`; new
   `OxidShopOrderServiceSecondSubmissionTest` (red 800≠799 → green).
2. `ca445b6` **`InFlightCheckoutAttemptResolver`** (+ interface, public service): open attempt via
   `OpenCheckoutAttemptRegistry::peek()`, contract neither terminal nor committed, has redirect URL,
   order `isNotFinished()` (new read on `NotFinishedOrderRepositoryInterface`), basket total within
   half a cent → returns the URL. **`PreviousCheckoutAttemptCleaner`** forgets `sess_challenge` when it
   names the retired order (optional `SessionAdapterInterface`, wired to `OxidSessionAdapter`).
3. `38b0bd4` **Provider redirect URL persisted** in `OXPROVIDERDATA` (`{"redirectUrl": …}`) and hydrated —
   it was dropped on save before, so nothing could ever be replayed.

## Gates (final)

Unit 1367 green (4 skipped, pre-existing) · Integration: contract repository 22 (1 skipped), not-finished
repository + second submission 14, shipping address 2 · PHPCS clean · PHPMD exit 0 · PHPStan: 20 findings,
identical to `b-7.4.x` HEAD when analysed inside the shop container (dynamic `oxorder__*` properties).

## Consumers

mollie-payment `fc9d87f` asks the resolver from `MollieOrderController::execute()` (through
`Service\InFlightCheckoutReplay`). Its CI pins `PAYMENT_BASE_BRANCH` to this branch until merged.
Stripe / PayPal inherit stories 1–3 (no phantom, challenge rotation) and can add the replay call later.
