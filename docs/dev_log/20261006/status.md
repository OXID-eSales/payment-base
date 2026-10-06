# Status — dev_log 20261006 · Sprint 15 GRAPH-QL (payment-base)

**Branch:** `b-7.4.x-GRAPH-QL` (payment-base; same name in stripe, paypal, mollie-payment)
**Sprint:** [sprints/sprint-15-GRAPH-QL-headless-checkout.md](sprints/sprint-15-GRAPH-QL-headless-checkout.md)
**Ritual per story:** this file updated · report in `done/` · sound played.

| Story | State | Notes |
|---|---|---|
| S1 Basket provider | IN PROGRESS | red tests being written |
| S2 Checkout context | TODO | |
| S3 Attempt guard | TODO | |
| S4 Contract commit service | TODO | |
| S5 Return-URL policy | TODO | |
| S6 GraphQL glue | TODO | needs graphql-storefront in require-dev |
| S7 ACP on the same path | TODO | |
| S8 Gates, consumers, hand-over | TODO | |

## Baseline (2026-10-06, before S1)

- Unit: run via `docker compose exec -T php php extensions/payment-base/vendor/bin/phpunit -c extensions/payment-base/phpunit.xml`
- Integration: run via the **shop's** PHPUnit: `docker compose exec -T php php vendor/bin/phpunit -c extensions/payment-base/tests/phpunit-integration.xml`
  (payment-base's own phpunit binary collides with the shop's error handler under the shop bootstrap)
