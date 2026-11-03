# Sprint 16 — VR-OPS: pay1 recovery and a deploy that cannot leave the shop half-migrated

**Date:** 2026-10-30 (planned 2026-11-03) · **Epic:** VR (violations review 2026-10-30) · **Ticket:** tbd
**Branches:** `b-7.4.x-VR-16-deploy-hygiene` in payment-base (schema check command, mail fail-loud), the deploy
scripts' repo (wherever pay1's GitHub Actions deploy lives), stripe (endpoint registrar).
**Status:** PLANNED. **Priority: P0 — production is wrong right now.**
**Requirements:** [`_engeneering_requirements.md`](_engeneering_requirements.md)
**Sources:** [`../reports/violations-review-payment-base.md`](../reports/violations-review-payment-base.md) §4, C4;
[`../reports/violations-review-stripe.md`](../reports/violations-review-stripe.md) §4.1, §4.2 L-1/L-2;
[`../reports/violations-review-mollie.md`](../reports/violations-review-mollie.md) §4.1.

## Problem

1. **Migrations missing on pay1 (verified 2026-10-30, read-only query).** `oxmigrations_payment_base` ends at
   `Version20260924120000`; `Version20261026100000`, `Version20261028100000`, `Version20261029100000` (present in
   `vendor/oxid-esales/payment-base/migration/data`) never ran. Column `OXORDERMAILSTATE` is missing, so since
   2026-10-28 15:19 every order sends its confirmation mail **before payment** (48 ERRORs), and each failure logs the
   full rendered customer mail (≈105k lines of PII in `oxideshop.log`).
2. **Deploy order + stale container.** 10-30 02:45: whole-shop E_ERROR because stripe `services.yaml:471` / mollie
   `services.yaml:253` named `$earlyOrders` before the payment-base that has it was deployed. 10-30 10:18–12:15: 43
   Stripe webhook 500s + 17 OPC payment-list 500s from a stale `var/cache/container`. Same pattern 10-15 (Mollie),
   10-20 (Stripe), 10-22 (payment-base).
3. **The STRP-176 failure path is a fallback.** `OxidShopOrderService.php:329` catches the stash failure and sends the
   mails immediately; the schema drift went unnoticed for two days.
4. **Stripe endpoints on pay1.** Three stale endpoints on account `…RKy8lrhVfC` (420 signature failures, flaring
   10-27..29); the pay1 endpoint never subscribed `checkout.session.completed` /
   `payment_intent.amount_capturable_updated`; `WebhookEndpointRegistrar::create()` (`:42-48`) never looks up an
   existing endpoint, so duplicates accumulate.

## Stories

| # | Story | Red (first) | Green | Proof |
|---|---|---|---|---|
| O1 | **Recover pay1 now** (ops, owner's go required) | — | On pay1: `vendor/bin/oe-payment-base-migrate`; verify `OXORDERMAILSTATE` + `oxmigrations_payment_base` head = `Version20261029100000`; rebuild `var/cache/container`. Find why the root `post-update-cmd` hook did not fire in the pay1 deploy path and record it. | read-only SQL output before/after in `done/`; one Playwright order on pay1 → mail arrives **after** payment (Mailpit timestamp > PSP paid timestamp); no new `[STRP-176]` line in `oxideshop.log` |
| O2 | **Purge PII already written** (ops) | — | Rotate/trim pay1 `oxideshop.log`: drop the `[STRP-176]`/`OrderConfirmationMailHandler` blocks (10-28..10-30) and Mollie `Request body:` lines (10-22); archive nothing that contains them. | `grep -c 'OXORDERMAILSTATE' oxideshop.log` = 0; note in `done/` with line counts removed |
| O3 | **Schema check command in payment-base** | `SchemaCheckCommandTest`: DB without a pending migration's column ⇒ exit 1 + names the migration; up-to-date DB ⇒ exit 0 | `oe:payment-base:schema-check` (wraps doctrine-migrations `status`/`up-to-date` for the payment-base table) | Integration test against a DB with `Version20261028100000` rolled back |
| O4 | **Deploy pipeline order + gates** | dry-run of the deploy script on a scratch container reproduces the 10-30 02:45 fatal when providers go first | Script order: `composer install` (all modules) → `oe-payment-base-migrate` → `oe:payment-base:schema-check` (fail = abort) → `oe:module:…` → `rm -rf var/cache/container` + warm-up request → health check (`/index.php?cl=start` 200, webhook endpoints answer 400 on empty body, not 500) → switch traffic | deploy log of two pay1 deploys in `done/`; zero `TypeError … must be … given` in `oxideshop.log` within 10 min after deploy |
| O5 | **Fail loud instead of sending mails early (pb C4)** | `OxidShopOrderServiceTest::stashFailureDoesNotSendMails`: DBAL exception ⇒ exception propagates, mail sender never called; `…logsIdsOnly`: log context has `contractId`, `orderId`, `sqlState`, and no key whose value contains the recipient address | remove the catch-and-send-now branch at `OxidShopOrderService.php:329`; log `contractId`/`orderId`/SQLSTATE only (`DoctrineContractOrderMailStore` too) | Integration: missing column ⇒ order placement fails with a clear error (checkout shows generic error), no mail in Mailpit |
| O6 | **Stripe endpoints idempotent** (stripe) | `WebhookEndpointRegistrarTest::createReusesEndpointForSameUrl` and `…updatesEnabledEventsToCatalog` | `create()` lists endpoints (paginated, fix `StripeWebhookEndpointApi::listAll` 100-cap), updates the one whose `url` matches instead of creating; `enabled_events` = `WebhookEventCatalog` | On pay1: delete the 3 stale endpoints on `…RKy8lrhVfC`, re-run registration; next 24 h of `log/stripe`: 0 signature failures, ≥1 `checkout.session.completed` |
| O7 | **Alert on regressions** (ops) | — | A daily check on pay1 (cron, mails Mailpit/owner): count of `ERROR`/`E_ERROR` lines per module in the last 24 h + `schema-check` result | first report in `done/` |

## Definition of done

- pay1: migrations at head, container rebuilt, order mail after payment proven by Playwright on pay1.
- Two consecutive deploys with the new script, no 500 window in `oxideshop.log`.
- payment-base: O3 + O5 merged (additive; consumer test counts unchanged). stripe: O6 merged.

## Out of scope

OPC log PII (`DeliveryAddressService`, `GuestUserService` — separate OPC ticket, see payment-base report §4
"Constellation context"); OPC-289 tracer at ERROR level (OPC ticket).

## Risks

O5 turns a silent mis-send into a visible checkout failure when the schema is behind — intended; O3/O4 make sure the
schema is never behind on a deploy that takes traffic.
