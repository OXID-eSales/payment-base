# Status — dev_log 20261006 · Sprint 15 GRAPH-QL (payment-base)

**Branch:** `b-7.4.x-GRAPH-QL` (payment-base; same name in stripe, paypal, mollie-payment)
**Sprint:** [sprints/sprint-15-GRAPH-QL-headless-checkout.md](sprints/sprint-15-GRAPH-QL-headless-checkout.md)
**Ritual per story:** this file updated · report in `done/` · sound played.

**Sprint 15 is DONE in payment-base (S1–S8).** Next: provider stories P-Stripe / P-Mollie / P-PayPal on their `b-7.4.x-GRAPH-QL` branches — checklist in the S8 report.

| Story | State | Notes |
|---|---|---|
| S1 Basket provider | **DONE** 2026-10-06 `874fdca` | [done/sprint-15-S1-basket-provider.md](done/sprint-15-S1-basket-provider.md) — Unit 1410, Integration 135, gates green; fixed `DeliveryAddressHashService` ($_POST) on the way |
| S2 Checkout context | **DONE** 2026-10-06 | [done/sprint-15-S2-checkout-context.md](done/sprint-15-S2-checkout-context.md) — Unit 1433, Integration 139, gates green; `oe_payments_sessions` is the headless store |
| S3 Attempt guard | **DONE** 2026-10-06 | [done/sprint-15-S3-attempt-guard.md](done/sprint-15-S3-attempt-guard.md) — Unit 1455, Integration 142, gates green; `oe_payments_idempotency` first consumer |
| S4 Contract commit service | **DONE** 2026-10-06 | [done/sprint-15-S4-contract-commit-service.md](done/sprint-15-S4-contract-commit-service.md) — Unit 1466, Integration 142, gates green; responder left as is (reason in report) |
| S5 Return-URL policy | **DONE** 2026-10-06 | [done/sprint-15-S5-return-url-policy.md](done/sprint-15-S5-return-url-policy.md) — Unit 1488, Integration 142, gates green; setting `sPaymentBaseHeadlessReturnOrigins` |
| S6 GraphQL glue | **DONE** 2026-10-06 | [done/sprint-15-S6-headless-checkout-graphql-glue.md](done/sprint-15-S6-headless-checkout-graphql-glue.md) — Unit 1528, Integration 145, gates green; storefront installed in the dev shop (phase 0). **Follow-up 2026-10-06:** glue no longer `implements` graphql-base interfaces (module activation failed on a shop without GraphQL — CI red), `cancel` reloads the contract state; Unit 1557 |
| S7 ACP on the same path | **DONE** 2026-10-06 | [done/sprint-15-S7-acp-on-the-headless-path.md](done/sprint-15-S7-acp-on-the-headless-path.md) — Unit 1548, Integration 148, gates green; default `createCheckout()` + `commitPaid()`. **Follow-up 2026-10-06:** basket-payment assertion only with the storefront column (bare CI shop), `2f6544e` |
| S8 Gates, consumers, hand-over | **DONE** 2026-10-06 | [done/sprint-15-S8-gates-consumers-handover.md](done/sprint-15-S8-gates-consumers-handover.md) — all consumers green or pre-existing env issues; S3 interface addition corrected (`OpenAttemptFinderInterface`) |

## Baseline (2026-10-06, before S1)

- Unit: run via `docker compose exec -T php php extensions/payment-base/vendor/bin/phpunit -c extensions/payment-base/phpunit.xml`
- Integration: run via the **shop's** PHPUnit: `docker compose exec -T php php vendor/bin/phpunit -c extensions/payment-base/tests/phpunit-integration.xml`
  (payment-base's own phpunit binary collides with the shop's error handler under the shop bootstrap)
