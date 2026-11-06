# Sprint 28 (OMS) — done report, 2026-11-06

**Branch:** `b-7.4.x-order-mail-stash` (payment-base only), from `b-7.4.x` 7b53ad1 (v1.7.2).
**Status:** implemented, gates green, **not merged** — owner's no-ff merge into `b-7.4.x`, then deploy to pay1.
**Plan:** [`../sprints/sprint-28-OMS-order-mail-stash-answers-kept-not-changed.md`](../sprints/sprint-28-OMS-order-mail-stash-answers-kept-not-changed.md)

## Commits (one per story, gate green before each)

| Commit | Story | What |
|---|---|---|
| 4d66a14 | plan | sprint document |
| 0cf7d29 | S1 | **red**: `tests/Integration/Repository/DoctrineContractOrderMailStoreRestashTest` (identical re-stash is kept, changed mails replace, unknown contract is not kept); unit test's `affected 0` half made explicit (row gone → `false`); two unit tests for the zero-changed case and its failure wrapping. Integration test failed on the unchanged store with "the second stash of identical mails answered false". |
| e3ad212 | S2 | `stash()` = `changed === 1 \|\| isKept()`; `isKept()` selects the row by id **and** `OXORDERMAILSTATE = pending`, `OXORDERMAILS = :mails`, `OXORDERMAILSENTAT IS NULL`; `read()` and `isKept()` share `fetchRow()` (one try/catch, same `failure()` wrapping, VR-18 "ids not content" kept). |
| (this) | S3 | CHANGELOG v1.7.3, `metadata.php` 1.7.3, this report, `status.md`, plan status. |

Decision D1 applied as proposed: patch bump 1.7.3 (behaviour fix, no public API change). Owner may re-tag.

## Gate evidence (local, php 8.3.36 container, after S2)

| Gate | Result |
|---|---|
| `composer phpcs` | clean |
| `composer phpstan` | OK, no errors, no baseline change |
| `composer phpmd` | clean |
| `composer dead-code` | OK, no new member |
| `phpunit -c phpunit.xml --testsuite Unit` | **2095 tests, 5218 assertions, 0 failures**, 11 skipped (2093 before S1 + 2 new; the changed test kept its name) |
| `phpunit -c tests/phpunit-integration.xml --group database` (shop context) | **66 tests, 230 assertions, 0 failures**, 1 skipped (63 + 3 new) |
| end-to-end probe on the real `ContractOrderMailStoreInterface` service (rolled-back transaction) | before: `true, false, true, false` — after: `true, true, true, true` for first stash / identical / changed / identical |

## Consumer test counts

| Consumer | Result | Note |
|---|---|---|
| stripe, `tests/phpunit-unit.xml` standalone (`extensions/stripe/vendor/bin/phpunit`) | 1589 tests, 36 errors, 4 warnings | all 36 errors are `File source/config.inc.php was not found!` (standalone bootstrap without shop context: `StripePanelViewDataBuilderTest`, `StripeOrderControllerSecurityTest`, `StripeOrderControllerTest`); **none** names the mail store or `stash`. The in-shop run (`/var/www`, `-c extensions/stripe/tests/phpunit.xml`) does not start on this workstation: `Mollie\Controller\PaymentController_parent not found` (mollie class chain, unrelated). Same result expected before the change — the stripe suite only mocks `ContractOrderMailStoreInterface`. |
| mollie-payment, paypal, one-page-checkout, opalreturns | not run | none of them references `DoctrineContractOrderMailStore` (grep); they consume the interface, whose contract did not change. |

## What pay1 needs

Deploy payment-base v1.7.3 (order: payment-base → providers → OPC → migrate → modules → container cache). No migration, no data fix: the two stuck contracts (`9f7660af…`, `dd46c4c6…`, orders 2154/2155 NOT_FINISHED) proceed on the customer's next attempt once the stash answers `true`.

## Left open (out of scope, see plan)

- stripe: `AbstractStripeWebhookHandler::isOfReplacedAttempt()` logs the expected OPC-175 session expiry at error level — noise, separate one-story sprint in the stripe repo.
- payment-base: a `ShopOrderException` extractor for `ThrowableSummary` so `getErrorCode()` reaches the log line; needs the VR-18 content review before it ships.
