# Sprint 01 STRP-182 — S4: proof and record

**Status:** DONE 2026-11-26

## payment-base gates

| Gate | Result |
|---|---|
| `./bin/pre-commit-check.sh --full` | **`Status: COMMITABLE`** — phpcs, PHPStan, dead-code, PHPMD, PHPUnit Unit 2165 tests (11 skipped) |
| Integration (`tests/phpunit-integration.xml`, shop root; the script runs Unit only outside the shop) | 407 tests, 6 errors + 1 failure — **the same on `b-7.4.x` 3f417ef** (all in `GraphQL\Admin\AdminPaymentOperationsIntegrationTest` / `AdminModuleParityTest`: graphql-admin is not in this shop; see memory "graphql-admin is EE-only") |

## Consumers against the branch (before = `b-7.4.x` 3f417ef, change stashed)

The shop's `vendor/oxid-esales/payment-base` is a symlink to this checkout, so a consumer suite runs against what is
on disk.

| Consumer | Before | After | Note |
|---|---|---|---|
| mollie-payment (unit) | — | 935 tests, OK (8 skipped) | no change needed |
| paypal (unit) | 840, 9 errors | 840, 9 errors | same 9 (`PhpStan\OxidEntryPointUsageProviderTest`), not related |
| one-page-checkout (Unit) | 911, 1 error | 911, 1 error | same 1 (`PaymentController_parent` not found), not related |
| stripe (standalone unit) | 1663, 36 errors | **39 errors** without a fix → **34 errors** with the twin branch | see below |

### stripe: three tests broke, twin branch fixes the fixtures (test-only)

`StripeOrderControllerTest::testCheckoutSuccessDispatchesReturnAndAuthorizedEvents`,
`StripeOrderControllerSecurityTest::testCheckoutSuccessB7HappyPathReturnsThankyou` and
`StripeOrderControllerAgbConsentTest::testCheckoutSuccessClearsConsent` used a fake `findById()` that gave a fresh
`PaymentContract` — a **draft**. A real Stripe return never has a draft (the order is created before the redirect).
The tests passed only because the dispatcher was a mock; with STRP-182 the responder refuses the draft.

Branch `b-7.4.x-uncaught-exception-STRP-182` in stripe (from `b-7.4.x` 311709c), tests only:

- New fixture `tests/Unit/Stripe/Controller/ReturningContract.php`: `pending()` (draft → not_finished with an order →
  pending, the state of a real return) and `draft()`. The three fake repositories use `pending()` (the third copy is
  extracted, DRY).
- `StripeOrderControllerSecurityTest` B6 ("responder gives null → Payment verification failed") got its null only
  from the draft fixture without an order id; its option `dispatchOrderId` was never read. It now asks for
  `draftContract => true` and expects no dispatch — the STRP-182 refusal is the real reason for the null. The dead
  option `dispatchOrderId` is removed.
- Result: the standalone suite has 34 errors, all on the baseline list (no new error; two baseline errors —
  `B4AcceptsOwnContract…`, `…ProceedsWhenTheSessionPointsAtAnotherContract` — now pass). The baseline errors are local
  environment errors of stripe's own vendor tree (`source/config.inc.php` not found, `Safe\…` redeclared).
- Through the shop config (`tests/phpunit.xml`), every `checkoutSuccess` test passes, B6 and B7 included; the one error
  per file is a `createCheckoutSession` test (`OrderController_parent` not found), on the baseline too.
- The fixture works with the old and the new payment-base (same counts with the change stashed), so the stripe branch
  needs **no** CI pin and can merge before or after payment-base.
- stripe `./bin/pre-commit-check.sh --no-phpunit` → `COMMITABLE`.

## UI proof (D1)

No real provider leaves a contract draft, and `audit_fakepay` is not in this checkout. The unit chain test of S0 is
the proof (the same stack as the Mythos run, now green). Open: ask Mythos to run `repro-2026.11-123.php` again on
`opc-audit` after the merge. Expected: step 2 `responder: null` (the test module prints the step), contract `draft`,
0 paid orders.

## Follow-up (not in this sprint)

- `PaymentAuthorizedEventHandler` still skips a `not_finished` contract without a message (only when
  `EarlyOrderCreationHandler` fails between its two saves).
