# Sprint 16 — O3 + O5 (payment-base): schema gate, order mails fail loud

**Branch:** `b-7.4.x-VR-16-deploy-hygiene` (from `b-7.4.x` @ `d062955`) · **Date:** 2026-11-03
**Commits:** `deebae8` (O5), `df9e19f` (O3) · **Status:** done on the branch, not pushed, awaiting merge approval.

## O5 — an order whose mails cannot wait on its contract is refused

- `OxidShopOrderService::createOrder()`: no contract id / no store / contract row missing / store throws ⇒
  `ShopOrderException('order_mails_not_kept')` with `order_id`, `contract_id`, `cause` (class only). Same refusal as
  the rewrite path (`stashOrderMails()` + `orderMailsNotKept()` shared). The send-at-once fallback
  (`sendOrderMailsNow()`) and the `$mailSender` constructor argument are removed (class + `services.yaml`).
- `DoctrineContractOrderMailStore`: `RuntimeException` message = what failed + `SQLSTATE xxxxx`; the DBAL message only
  on `previous`. Reason: pay1's DBAL formats `… with params [...]`, i.e. the rendered mail went into `oxideshop.log`.
- Red → green: 4 pinned fallback tests (`…SendsTheMailsNow`, `…SendsThemNowAndTheCheckoutGoesOn`) replaced by
  `testCreateOrder_WhenTheMailsCannotWaitOnTheContract_RefusesTheOrder` (3 data sets),
  `…WithoutAStore_RefusesTheOrder`, `…WhenTheStoreFails_TheRefusalCarriesIdsAndTheCauseClassOnly`; store:
  `testADatabaseErrorKeepsTheSqlStateButNotTheDriverMessage`, `testAReadErrorKeepsTheSqlStateButNotTheDriverMessage`.
  7 red before the change, green after.
- Callers checked: every `createOrder()` caller (payment-base `EarlyOrderWriter`, one-page-checkout
  `CheckoutService:996`) passes `contract_id`; no module overrides the service's arguments.

## O3 — `vendor/bin/oe-payment-base-schema-check`

- Wraps `oe-eshop-db_migrate migrations:up-to-date oe_payment_base`; 0 up to date, 1 pending, 2 cannot check (never
  skips). Registered in `composer.json` `bin`.
- Red → green: `tests/Unit/Bin/SchemaCheckScriptTest` (4 tests, fake project root + stand-in wrapper).
- Real runs: local shop → exit 0; **pay1 (read-only, 2026-11-03) → exit 1, "Out-of-date! 4 migrations are available
  to execute."**

## Gates (in the php container, from the branch checkout)

- Unit: 1983 tests, 0 failures, 9 skipped (baseline before the sprint: 1976).
- `composer phpcs` clean · `composer phpstan` no errors · `composer phpmd` clean.
- Integration suite: **not run locally** — the shop's autoloader and compiled container point at the main checkout.
  CI (7.4 / 7.5 / 7.6) runs it on push; the branch is not pushed yet.

## Open in sprint 16

O1 (run the migration on pay1), O2 (purge the PII lines), O4 (deploy script order + gate — lives in the deploy repo),
O6 (stripe endpoint registrar), O7 (daily alert).
