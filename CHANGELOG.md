# Changelog

All notable changes to this module are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions adhere to [SemVer](https://semver.org/).

## [Unreleased]

### Fixed
- Orders where the shopper ships to their billing address ("use billing address for shipping")
  now carry that address in the order's shipping columns too. Core's `Order::setUser()` only
  writes `OXDEL*` when a separate `oxaddress` row was selected, so every such order — for every
  provider using this module's `OxidShopOrderService` (Mollie, Stripe, PayPal) — left the admin
  *Addresses* tab's Shipping Address section empty; the merchant had to know the convention and
  read the billing block instead. `OxidShopOrderService::setOrderFieldsAfterCreation()` now copies
  billing into the 13 `OXDEL*` columns whenever none was already set by core, immediately before
  its existing save; an order with a separately selected delivery address is untouched. Visible
  side effect: order confirmation e-mails and the thank-you page print a shipping block wherever
  the core template keys on `oxdellname`, so those now show the billing address as shipping for
  these orders too, matching what the admin screen shows.

## [v1.2.3] - 2026-09-15

### Added
- `extra.branch-alias` (`dev-b-7.4.x` => `1.2.x-dev`). The consuming modules require
  `>=v1.2`, and their CI installs this package from a path repository, where composer sees
  `dev-b-7.4.x` — a dev branch does not satisfy a numeric range on its own. With the alias it
  does, so no workflow has to carry an explicit `as <version>` pin that goes stale on every
  release.

### Changed
- `UnifiedNamespaceClassmapTest` no longer asserts `extra.oxideshop.target-directory`. Since
  OXID 7 a module is not copied to `source/modules/` at all: it stays in `vendor/` and only
  `assets/` is symlinked to `source/out/modules/<moduleId>`. The key is read by
  `ThemePackageInstaller` alone — `ModulePackageInstaller::getModuleTargetDir()` has no caller.
  The package type and the module id are still guarded.

## [v1.2.2] - 2026-09-15

### Added
- Module logo: `assets/img/logo.png` and the matching `thumbnail` entry in `metadata.php`, so the
  admin module page shows the OXID module logo instead of the shop's generic placeholder. It is
  the same file the One-Page Checkout already used, and it is now the shared default for the
  payment modules.

## [v1.2.1] - 2026-09-15

### Changed
- Packaging for composer publication: PHP requirement raised to `^8.2` (the code uses readonly
  classes), runtime dependencies declared explicitly (`symfony/console`, `symfony/filesystem`,
  `doctrine/migrations`, `ext-json`), the unused `psr/event-dispatcher` requirement dropped and a
  `conflict` with OXID eShop below 7.4 added.
- `metadata.php` version now matches the package version. The obsolete
  `extra.oxideshop.target-directory` and the Smarty-era `templates` entry were removed — since
  OXID 7 the module stays in `vendor/` and Twig resolves `@oe_payment_base/...` from `views/twig`.

### Added
- `.gitattributes`: tests, generated documentation, sprint logs, CI workflows, developer scripts
  and the static analysis configuration are no longer part of the composer package. LICENSE,
  README and this changelog stay in it.

## [v1.2.0] - 2026-09-04

### Added
- Single active payment method or delivery set is assigned automatically and its checkout step is
  skipped (`blPaymentBaseAutoAssignSinglePayment`, `blPaymentBaseAutoAssignSingleShipping`).
- Vouchers return to the pool when an order is cancelled or deleted
  (`blPaymentBaseReleaseVouchersOnOrderEnd`).
- Console command `oe:payments:not_finished:cleanup` collects abandoned `NOT_FINISHED` orders
  (`iPaymentBaseCleanupPeriod`), with a configurable stale-checkout horizon
  (`iPaymentBaseStaleCheckoutMinutes`).
- Optional `idempotencyKey` on `RefundPaymentRequest`.
- A payment that is still settling is shown as a notice on the thank-you page instead of an error.

### Changed
- The fraud audit record is separate from the blocking policy.
- Order finalization is owned by payment-base instead of whichever module was merged last.

### Fixed
- A retried checkout retires the order the previous attempt left behind.
- A return controller can reach the attempt cleaner.
- A committed contract can no longer be expired.

## [v1.1.0] - 2026-08-11

Added iframe feature

## [v1.0.0] - 2026-06-26

## Released

## [v1.0.0-RC1] — 2026-06-20

### Added
- First release
