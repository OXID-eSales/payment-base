# Status — dev_log 20261006 · Sprint 15 GRAPH-QL (payment-base)

**Branch:** `b-7.4.x-GRAPH-QL` (payment-base; same name in stripe, paypal, mollie-payment)
**Sprint:** [sprints/sprint-15-GRAPH-QL-headless-checkout.md](sprints/sprint-15-GRAPH-QL-headless-checkout.md)
**Ritual per story:** this file updated · report in `done/` · sound played.

| Story | State | Notes |
|---|---|---|
| S1 Basket provider | **DONE** 2026-10-06 `874fdca` | [done/sprint-15-S1-basket-provider.md](done/sprint-15-S1-basket-provider.md) — Unit 1410, Integration 135, gates green; fixed `DeliveryAddressHashService` ($_POST) on the way |
| S2 Checkout context | **DONE** 2026-10-06 | [done/sprint-15-S2-checkout-context.md](done/sprint-15-S2-checkout-context.md) — Unit 1433, Integration 139, gates green; `oe_payments_sessions` is the headless store |
| S3 Attempt guard | **DONE** 2026-10-06 | [done/sprint-15-S3-attempt-guard.md](done/sprint-15-S3-attempt-guard.md) — Unit 1455, Integration 142, gates green; `oe_payments_idempotency` first consumer |
| S4 Contract commit service | IN PROGRESS | discovery: `ContractCommitmentHandler`, `PaymentAuthorizedEvent`, stale cleanup ordering |
| S5 Return-URL policy | TODO | |
| S6 GraphQL glue | TODO | needs graphql-storefront in require-dev |
| S7 ACP on the same path | TODO | |
| S8 Gates, consumers, hand-over | TODO | |

## Baseline (2026-10-06, before S1)

- Unit: run via `docker compose exec -T php php extensions/payment-base/vendor/bin/phpunit -c extensions/payment-base/phpunit.xml`
- Integration: run via the **shop's** PHPUnit: `docker compose exec -T php php vendor/bin/phpunit -c extensions/payment-base/tests/phpunit-integration.xml`
  (payment-base's own phpunit binary collides with the shop's error handler under the shop bootstrap)
