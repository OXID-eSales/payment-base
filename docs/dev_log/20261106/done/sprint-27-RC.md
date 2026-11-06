# Sprint 27 (RC) — done report, 2026-11-06

**Branch:** `b-7.4.x-rounding-correction` (payment-base only), from `b-7.4.x` e35a849 (v1.7.0; tag v1.7.1 = metadata version fix only, off-branch).
**Status:** implemented, gates green, **not merged**. S5 sandbox proof (one order per provider) is open — needs sandbox credentials and a browser session.

## Commits (one per story, gate green before each)

| Commit | Story | What |
|---|---|---|
| b947e62 | plan | sprint document |
| 07a6fe2 | S1 | `MetadataExtendTest` red → `oxPriceList` extend row, `PriceList`, `PriceToTaxableLineMapper` and their 3 tests deleted |
| 1de0327 | S2 | `MetadataSettingsTest` red → setting `blPaymentBasePerLineVat`, group `per_line_vat`, 2×2 admin labels deleted; `SettingsTranslationsTest` docblock |
| b3cd084 | S3 | `PriceList_parent` + `Price` stubs out of both bootstraps; calculator no longer `public`; kept math listed in `dead-code-baseline-kept.neon` (D1 a) |
| c15f2ca | S4 | `docs/invariants/oxid-totals-win.md` (I1/I2); `money-arithmetic.md`; CHANGELOG v1.8.0; metadata 1.8.0 (D2) |

Decisions applied as proposed: D1 (a) keep the math, baseline with reason; D2 1.8.0; D3 all three providers (open, see below); D4 keep the characterisation tests.

## Gate evidence (local, php 8.3.36 container)

| Gate | Result |
|---|---|
| `phpunit --testsuite Unit` | **2093 tests, 5208 assertions, 0 failures**, 11 skipped (was 2091 before S1/S2 added 4 and deleted 2) |
| `composer phpstan` | OK, no errors, no baseline change |
| `composer dead-code` | OK (3 kept-math members baselined with reason; nothing else new) |
| `composer dead-assets` | clean (the two deleted language keys left no orphan) |
| `composer phpcs`, `composer phpmd` | clean |
| Integration (`tests/phpunit-integration.xml`, local shop) | 380 tests: **373 pass**, 7 not green — all in `Integration/GraphQL/Admin/*` (`AuditChainRepositoryInterface` not found, `StepUp::require` signature): the local `oxid-admin-graphql` checkout is behind payment-base's parity expectations. Unrelated to this sprint (no VAT/basket/price test is touched); not re-run on the base commit. |

Noted, not fixed (pre-existing): `bin/pre-commit-check.sh` runs PHPStan file-by-file on changed files and so analyses `metadata.php`, which the project config never does → `class.notFound` for the OXID controller classes. The untouched base `metadata.php` fails the same way (plus the PriceList classes). `composer phpstan`, the CI gate, is green. Local `composer install` cannot run because `shipmonk/dead-code-detector` is missing from the (git-ignored) `composer.lock`; installed locally with `composer update shipmonk/dead-code-detector -W` to run the dead-code gate here.

## CI (pushed 2026-11-06 10:38 UTC, branch `b-7.4.x-rounding-correction` at 01ed363)

| Workflow | Run | Result |
|---|---|---|
| Secret scan | 42204265063 | green, first attempt |
| Tests on OXID 7.5 | 42204265046 | green, first attempt (styles, install with/without, unit 8.3/8.4, integration 8.3/8.4 with/without) |
| Tests on OXID 7.4 | 42204265006 | green on attempt 3 (styles, dead-code, install, unit, integration) |
| Tests on OXID 7.6 | 42204265139 | green on attempt 4 (styles 8.3/8.5, install, unit, integration) |

The red attempts were all `curl error 7 … api.github.com port 443: Could not connect` during `composer install`
(85–835 refused downloads per job, exit 100) — the shared-runner burst the workflow header documents — never a
test, style or dead-code failure. A failed-jobs-only re-run cannot recover (`fail-on-cache-miss`: the test jobs
need the install job's cache from the same attempt); re-run the whole workflow.

## Open (S5)

- Sandbox proof, one OPC order per provider, to show the wire reconciliation is unchanged: PayPal purchase unit, Mollie lines, Stripe line items. Report to `reports/rc-27-basket-vat-proof.md` with order ids.
- Owner: no-ff merge into `b-7.4.x`, tag v1.8.0.
