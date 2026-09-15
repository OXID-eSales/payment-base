# Changelog

All notable changes to this module are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions adhere to [SemVer](https://semver.org/).

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
