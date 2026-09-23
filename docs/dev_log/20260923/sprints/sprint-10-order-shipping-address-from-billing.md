# Sprint 10 — Fill the order's shipping address from billing when the shopper ships to billing

**Branch:** `b-7.4.x-order-shipping-address-from-billing` (off `b-7.4.x`)
**Module:** `payment-base` — decided by the product owner on 2026-09-23: shop-level order behaviour
for every provider, not a Mollie concern. Mollie contributes only the e2e repro.
**Engineering requirements:** [`../../20260903/sprints/_engeneering_requirements.md`](../../20260903/sprints/_engeneering_requirements.md) — binding.
**Master plan (all four stories, findings, DB evidence):**
`../../../../mollie-payment/docs/dev_day_log/20260923/sprints/01-order-shipping-address-from-billing.md`
**Estimated size:** ~45 LOC production (1 new class + 3 lines in `OxidShopOrderService` + 2 lines `services.yaml`),
~150 LOC tests (7 unit, 2 integration).
**Status:** DONE (2026-09-23) — Stories 2, 3, 4 all landed.

## Why

A shopper who ships to the billing address places an order through any payment-base provider. Core's
`Order::setUser()` writes `OXDEL*` only when a separate `oxaddress` row was selected, so the admin
*Addresses* tab shows an empty Shipping Address for every such order (local DB: 0 of 245 Mollie,
0 of 250 Stripe orders carry `OXDELLNAME`). The merchant cannot see where the parcel goes without
reading the billing block and *knowing* the convention.

## Where

`OxidShopOrderService::setOrderFieldsAfterCreation()` — the one post-`finalizeOrder()` seam all
providers pass through, already owning the single `save()`.

## Stories (payment-base part; numbering follows the master plan)

- **Story 2** — DONE. `src/Adapter/OrderShippingAddressCopier.php`: `copyBillingWhenShippingEmpty(Order): bool`,
  13 billing→shipping twins (`company fname lname street streetnr addinfo city countryid stateid zip fon fax sal`),
  skips when `oxdellname` or `oxdelfname` is set, never saves. 7 unit tests (`tests/Unit/Adapter/OrderShippingAddressCopierTest.php`),
  all green, 71 assertions. Declared in `services.yaml` (no interface). Gates: `composer phpcs` ✓,
  `composer phpstan`/`vendor/bin/phpstan analyse` (whole `src/`) ✓ — no new baseline entries, `composer phpmd`
  (whole `src/`) ✓ — baseline unchanged, `composer test-unit` ✓ (1345 tests, 6 pre-existing skips, 0 failures).
  `./bin/pre-commit-check.sh --full` ✓ overall, though its PHPStan/PHPMD steps no-op on untracked new files
  (`git diff HEAD` doesn't list them) — verified those two gates manually instead (see above).
  Bootstrap notes for Story 3: `tests/bootstrap-unit.php`'s `Order` stub gained `save()` and
  `#[AllowDynamicProperties]`; a minimal `OxidEsales\Eshop\Core\Field` stub (T_RAW branch only) was added
  there too. `tests/PhpStan/phpstan-bootstrap.php`'s `Order` stub gained the 13 declared `oxorder__oxdel*`
  properties (PHPStan resolves the copier's interpolated property names against a literal-string union from
  the `FIELD_SUFFIXES` const, so it needed them declared, same pattern as the existing `oxorder__oxfolder`
  etc.). The unit test mocks `Order::getFieldData()` via `willReturnCallback` over a value map — it does not
  set `oxorder__oxbill*` properties.
- **Story 3** — DONE. `OxidShopOrderService` now takes `OrderShippingAddressCopier $shippingAddressCopier`
  (2nd constructor arg); `setOrderFieldsAfterCreation()` calls
  `copyBillingWhenShippingEmpty($order)` immediately before the existing `$order->save()`. `services.yaml`'s
  `ShopOrderServiceInterface` definition gained the explicit `$shippingAddressCopier` argument. The three
  unit-test call sites in `OxidShopOrderServiceTest.php` pass `new OrderShippingAddressCopier()` (note: PHP
  does not error on the extra positional argument to the old 1-arg constructor, so these three tests stayed
  green throughout rather than going red — the copier simply wasn't being used before the constructor
  changed; the integration tests below are the ones that prove the real behaviour and did go red first).
  New `tests/Integration/Adapter/OxidShopOrderServiceShippingAddressTest.php` (`@group integration`), 2 tests:
  `testCreateOrder_WhenUserShipsToBillingAddress_PersistsBillingIntoShippingColumns` (fixture user + basket,
  no `deladrid`, real `createOrder()` through the container-resolved `ShopOrderServiceInterface`, reloads the
  order and asserts all 13 `OXDEL*` columns equal their `OXBILL*` twins — confirmed red before the fix:
  `oxdelcompany` `''` vs expected `'Bill-Company'`) and
  `testCreateOrder_WhenUserSelectedADeliveryAddress_KeepsThatAddress` (fixture `oxaddress` row + `deladrid`
  in session, asserts `OXDELLNAME` is the address row's last name, already green before the fix — the
  regression guard). This shop's core payment methods (`oxidinvoice` etc.) are deactivated (only
  `oe_payments_mollie` / `oe_payments_stripe_wallet` are active), so the fixture basket uses
  `oe_payments_mollie` as payment id — proves the fix is provider-agnostic rather than depending on a
  specific PSP. Writes happen inside `IntegrationTestCase`'s per-test DB transaction, rolled back in
  `tearDown()` — no manual fixture cleanup needed. Added a `NoConcreteClassTypeHintRule` ALLOWED_PATTERNS
  entry for `OrderShippingAddressCopier` (one impl, one caller — same reasoning as the existing `RuleSet` /
  `ValidationRequestContext` VO exemptions), not a baseline suppression.
  Gates: `composer phpcs` ✓, `composer phpstan` (full `src/`, cache cleared) ✓ — no new baseline entries,
  `composer phpmd` (full `src/`) ✓ — baseline unchanged, `composer test-unit` ✓ (1345 tests, 0 failures, 4-6
  pre-existing skips depending on run), integration suite ✓ (125 tests, 1 pre-existing skip).
  `./bin/pre-commit-check.sh --full` (from the SDK root, not inside the container — it shells out to
  `docker compose exec` itself): phpcs ✓, unit ✓, dead-code heuristic ✓ (harmless `integer expression
  expected` noise), phpmd ✓; its single-file PHPStan step reports 3 false positives
  (`oxNew`/`Registry::get*`/mixed-return errors) because `tests/PhpStan/phpstan.neon` (used only by this
  script's changed-files mode) lacks the OXID-core `ignoreErrors` entries the module-root `phpstan.neon`
  has (used by `composer phpstan`, which is clean) — a pre-existing gap between the two configs, not a
  regression from this story. Verified manually per the documented caveat.
- **Story 4** — DONE. Cache cleared, OPC's `oeOnePageCheckoutEnabled` flipped off for the run (restored to
  `true` afterwards, diffed identical to the pre-change backup). `npx playwright test
  --project=mollie-standard OrderShippingAddressFromBilling` → GREEN (was red on order 605 in Story 1; new
  orders 610–612 all show `OXDELLNAME`/`OXDELSTREET` equal to billing). Full `--project=mollie-standard`
  regression: 11 passed, 2 skipped (`KlarnaOrderData`, `KlarnaEndToEnd` — pre-existing environment skip, not
  failures), 0 failures. DB check:
  `SELECT OXORDERNR, OXPAYMENTTYPE, OXBILLLNAME, OXDELLNAME, OXDELSTREET FROM oxorder ORDER BY OXORDERDATE
  DESC LIMIT 3` → orders 610/611/612, `oe_payments_mollie`, `OXBILLLNAME`/`OXDELLNAME` both `Muster`,
  `OXDELSTREET` `Hugo-Junkers Str`. `CHANGELOG.md` `[Unreleased] / Fixed` entry added, incl. the order-mail /
  thank-you-page side effect.
  LOC: production ≈ 56 (`OrderShippingAddressCopier.php`) + 12 (`OxidShopOrderService.php` diff) + 6
  (`services.yaml`) + 5 (`NoConcreteClassTypeHintRule.php` exemption) ≈ 79. Tests ≈ 171
  (`OrderShippingAddressCopierTest.php`) + 181 (`OxidShopOrderServiceShippingAddressTest.php`) + 9
  (`OxidShopOrderServiceTest.php` diff) ≈ 361.

## Not in this sprint
- Core-only payment methods (invoice, cash on delivery) never enter payment-base — an OXID core ticket
  if parity is wanted there.
- Backfilling existing orders.
- An interface for the copier, a config switch, or a decorator around the order service.

## Gates
`composer phpcs` · `composer phpstan` · `composer phpmd` · `composer test-unit` ·
`./bin/pre-commit-check.sh --full` before commit · `var/cache` cleared in the PHP container.

## CI follow-up (2026-09-23, after the first push)

The two integration tests were green locally and red in CI (`Failed asserting that false is of
type string`). Local shop = EE with demo data and the Mollie module active; CI = bare CE from
`initial_data.sql`: no articles, no `oxobject2payment` rows, no PSP module. The fixture had
leaned on both (`SELECT … FROM oxarticles LIMIT 1`, `oe_payments_mollie`). The test now creates
its own active article and its own payment method assigned to `oxidstandard` (the only delivery
set initial data ships, and it is active), inside the rolled-back transaction. Mail was ruled out
as a cause: both environments use the SDK PHP image with msmtp. Re-verified locally: 2 tests, 17
assertions, no fixture rows left behind; phpcs and phpstan clean.

Second CI run, same job: `Function 'setOrderNumber' does not exist or is not accessible`.
`setOrderNumber()` is a public method the Stripe and OPC `Order` extensions add; payment-base's
`setOrderFieldsAfterCreation()` called it after `save()`, so payment-base order creation only
ever worked with one of those modules in the class chain. Core's `finalizeOrder()` already draws
the number (`setNumber()` at the end of the OK path), so the call was removed rather than
re-implemented, and the integration test now asserts `oxordernr > 0`. Latent production bug for
Mollie-only / PayPal-only shops; CHANGELOG entry added. All gates re-run green locally
(Integration 125, Unit 1345, phpcs/phpstan/phpmd clean).
