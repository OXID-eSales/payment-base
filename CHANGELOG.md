# Changelog

All notable changes to this module are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions adhere to [SemVer](https://semver.org/).

## [Unreleased]

### Added
- `InFlightCheckoutAttemptResolverInterface` (MOL-18): answers whether the session already has a
  checkout attempt in flight - an open contract with a provider checkout URL whose order is still
  `NOT_FINISHED` and whose basket total matches the live basket - and hands back that URL so a
  provider's order controller can replay the redirect instead of starting a second attempt.
  `OpenCheckoutAttemptRegistryInterface::peek()` reads the remembered attempt without consuming it;
  `NotFinishedOrderRepositoryInterface::isNotFinished()` reads an order's `OXTRANSSTATUS`.

### Fixed
- "Order now" clicked twice no longer writes a phantom order (MOL-18). Core's `finalizeOrder()`
  answers `ORDER_STATE_ORDEREXISTS` when `sess_challenge` already names an order row;
  `OxidShopOrderService` treated that as success and saved the never-loaded `Order` object - a
  second row with no user, no articles, no payment type and total 0, which the shopper was then
  sent to the PSP to pay for. `createOrder()` now throws `ShopOrderException` with code
  `order_exists` and writes nothing.
- A retired checkout attempt now forgets the session's `sess_challenge` when it names the retired
  order (MOL-18). The order row is kept (storno, `CANCELLED`) for a gap-free number sequence, so
  while the challenge still pointed at it core refused every further attempt in the session with
  `ORDEREXISTS`; the phantom order above was what made the retry appear to work.
- The provider checkout URL handed to `PaymentContract::setProvider()` is now persisted
  (`OXPROVIDERDATA` as `{"redirectUrl": ...}`) and restored on load (MOL-18). It used to live only in
  memory, so every contract loaded from the database answered `null` to `getProviderRedirectUrl()`.
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
- Order creation in `OxidShopOrderService` no longer calls `setOrderNumber()`, a method only the
  Stripe and one-page-checkout Order extensions provide. Core's `finalizeOrder()` already draws
  the number; on a shop without one of those modules active every payment-base order creation
  threw "Function 'setOrderNumber' does not exist or is not accessible".

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
