# Sprint 25 — VR-DRY: shared pieces live in payment-base once; required means required

**Date:** 2026-10-30 (planned) · **Epic:** VR · **Ticket:** tbd
**Branches:** `b-7.4.x-VR-25-dry` in payment-base, stripe, mollie-payment, paypal.
**Status:** PLANNED · **Priority:** P2 · **Depends on:** sprints 19–23 (they already move the guard chain, token
service, refund recorder, stock restoration, late-money refunders, capture/refund policy).
**Requirements:** [`_engeneering_requirements.md`](_engeneering_requirements.md) — "One path, no fallbacks".
**Sources:** stripe D-3, D-4, D-7, D-11; mollie D-M1, D-M2, D-M4, DRY table, C-M1..C-M3; paypal Q-1, Q-2, D-3, D-4,
D-5, C-2; payment-base C2, C3, D2, D3, Low (Money as float, reflection hydration, `PaymentContract` size,
`AMOUNT_TOLERANCE` twice).

## What is still duplicated after sprints 19–23

| Piece | Copies | Target in payment-base |
|---|---|---|
| `UserDataValidator` | stripe, mollie (64 differing lines) | `Validation\UserDataValidator` |
| `OrderActionDispatcher` (admin) | stripe, mollie, paypal (142–176 lines differ) | generic dispatcher over `MoneyOperationRunner` (sprint 22); providers add extras only |
| `OxidContractLinkedOrderUpdater` | stripe, mollie | `Adapter\OxidContractLinkedOrderUpdater` |
| `ModuleConfigurationService`, `PaymentMethodInstaller` | stripe, mollie, paypal | abstract settings / payment-method installer base |
| `OxpaidReconciliation*` + `ReconcileOxpaidCommand` | stripe, mollie, paypal | one command `oe:payments:reconcile-oxpaid` + provider status port |
| early-order creation | paypal `PayPalOrderCreationHandler` (183 lines) | payment-base `EarlyOrderCreationHandler` (configure, delete PayPal copy) |
| agnostic contract transitions (cancel/fail/expire + order mirroring) | stripe `WebhookContractFulfillmentHandler.php:132-267`, mollie twin (442 lines) | `Contract\ContractTerminationService` |
| checkout context building | paypal ×3 (`PayPalOrderController:82-97,216-233`, `CheckoutEventContextBuilder`) | one builder (PayPal-internal) |
| HTTP clients | paypal SDK + `CurlPayPalRestTransport` + `CurlConnectHttpInvoker` | one transport (PayPal-internal; sprint 17 decides which are dead) |

## Stories

| # | Module | Story | Red (first) | Green | Proof |
|---|---|---|---|---|---|
| D1 | payment-base | Shared pieces (table rows 1–7) | each moved class arrives with the **union** of both providers' tests, run in payment-base; differing behaviour is decided explicitly (owner) and recorded in `done/` | classes + tags | consumer counts recorded |
| D2 | stripe / mollie / paypal | Adopt D1, delete copies | provider tests retargeted, not deleted, where they cover provider-specific wiring | deletions | Playwright smoke per provider (checkout, admin capture, admin refund) |
| D3 | all four | **Required collaborators** — no `?X $x = null`, no `@?`, no `?? new …` in production constructors (pb C2, stripe D-4 (27×), mollie D-M2 (~20×), paypal Q-2 (18×) / C-2 silent `ContainerFacade::get()` nulls) | per module a `ContainerBootTest` that compiles the container and asserts every payment service resolves; a phpstan rule / grep check in CI for `= null` defaults on constructor params of classes in `src/**/Service|Handler|Controller` | make them required; delete the branches they selected | CI green; `EarlyOrderCreationHandler` throws on missing payment id instead of `'unknown_payment'` |
| D0 | payment-base + 3 providers | **Shared DI ids bound by several modules** (sprint 17 finding): `ShopAdapterInterface` (and `SessionAdapterInterface` copies) are bound by Mollie, Stripe and PayPal; the last-merged `services.yaml` wins, so Stripe can run on Mollie's adapter (`isTestMode` of the wrong provider). | `ContainerBootTest` with all three providers active: each provider's services receive **their own** shop adapter (or one agnostic payment-base adapter) | payment-base owns one `ShopAdapterInterface` implementation; provider-specific bits (test mode, keys) move to provider-named services; delete the copies (Mollie/Stripe/PayPal `OxidSessionAdapter`, `OxidShopAdapter`, Stripe `Core/ShopCurrency`) | activate all providers on a scratch shop, assert in a Playwright admin check that each provider shows its own test/live mode |
| D4 | payment-base | Interface over concrete (D2), shop id from context (C3) | `EarlyOrderCreationHandlerTest` with a non-`PaymentContract` implementation; `ContractServiceTest::shopIdFromContext` (EE subshop fixture) | use `PaymentContractInterface`; inject `ShopContext` | EE subshop smoke if an EE environment is available (else record as open) |
| D5 | stripe / mollie / paypal | God classes (stripe `StripeOrderController` 776, `StripePaymentHandler` 541, `Admin/ModuleConfiguration` 509; mollie `WebhookContractFulfillmentHandler` 442 → one class per status; paypal `PayPalOrderController` 381 → start/return/cancel) | characterisation tests first where coverage < 80 % | splits, no behaviour change | PHPMD baselines shrink (record before/after) |
| D6 | all four | Comment archaeology (mollie C-M1: 186 Sprint/Story tags in `src/`; same pattern elsewhere) | — | move history to dev logs, keep the "why"; fix stale docblocks (mollie `OrderActionDispatcher.php:36`, `WebhookContractFulfillmentHandler.php:206-210`; paypal `CaptureService.php:27-30`; stripe module CLAUDE.md) | — |
| D7 | payment-base | Money type (Low, optional — owner decision) | `Money` in `CaptureRefundTracker` and events; `AMOUNT_TOLERANCE` once (`Money::equals()`) | — | — |

## Definition of done

The sibling-diff table above has no row left. `grep -rE '\?\w+ \$\w+ = null' src/` in each module lists only
value objects / DTOs. Reports in `done/`.
