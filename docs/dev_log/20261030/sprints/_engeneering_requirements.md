# Engineering requirements — dev_log 20261030 (violations review sprints 16–25)

Binding for every sprint in this folder. Carried over from
[`20260903`](../../20260903/sprints/_engeneering_requirements.md) unchanged; this file only adds the rules
that the 2026-10-30 violations review showed were being broken, so each sprint can point here instead of
repeating them.

## Core requirements (unchanged)

TDD-first · DevOps-first (`composer phpcs`, `composer phpstan` level max, `composer phpmd`, PHPUnit Unit +
Integration green before every commit) · proven through the UI (Playwright for anything a shopper or merchant
clicks) · SOLID · DRY · agnosticism (payment-base and one-page-checkout know no provider) · additive only for
payment-base public API · no overengineering · never suppress static analysis.

## Added for sprints 16–25

| Rule | What it means here | Violations it answers |
|---|---|---|
| **One path, no fallbacks** | A required collaborator is required: `@`, never `@?`; no `?X $x = null` that silently switches to a "legacy ladder"; no `?? 'EUR'`, `?? contract amount`, `'unknown_payment'`, "degraded fallback". A misconfiguration fails at container boot or with a 500, never as a quiet second code path. | pb C2/S3, Mollie D-H1/D-M2, PayPal Q-2/C-2/SEC-5, Stripe D-4/D-11 |
| **Strict 1:1 order ↔ contract** | No capture/refund/commit without a contract; no PSP id taken from the request when the contract already names it. | Stripe S-1/D-8, PayPal SEC-1 |
| **Money moves are idempotent by PSP id** | Every capture/refund/chargeback is booked by its provider id (unique key, insert-first, amount only if inserted). Every outbound mutating PSP call carries an idempotency key that is unique per *attempt*, not per contract. | Mollie S-H1/S-M4/S-M6, PayPal SEC-3/SEC-4/C-3, Stripe S-4, pb S4/S5 |
| **Money invariants live in the aggregate** | `captured ≤ authorized`, `refunded ≤ captured` are enforced by payment-base (`CaptureRefundTracker`), not by each provider. | pb S6/D1/D4 |
| **Catch `Throwable` at process boundaries** | Webhook processing, cron commands and controllers that hold a claim/lock must release it on `\Error` as well. | pb S1, Stripe S-3, Mollie S-M5, PayPal SEC-2 |
| **Logs carry ids, never content** | Log contract id, order id, PSP ids, SQLSTATE, HTTP status, reason codes. Never: rendered mails, request/response bodies, addresses, e-mails, tokens (`contract_token`, `cardToken`, `client_secret`, access tokens). Each provider webhook file log records `eventId`, `eventType`, `objectId`, `contractId`, `action`, `reason`, `httpCode`. | pb C4, Mollie log/request body, Stripe L-3, PayPal §4.2 |
| **Deploy is a tested artefact** | Order: payment-base → providers → OPC → `oe-payment-base-migrate` → `oe:module:…` → rebuild `var/cache/container` → warm-up → traffic. A schema check fails the deploy, not the first order. | pay1 §4.1 of all reports |
| **Dead-code gate** | Each module runs a dead-code check in CI (sprint 17, story DC-5): no class, service or `fnc` endpoint without a production caller; tests alone do not keep code alive. New code without a caller is a review blocker. | pb C1/D10, PayPal C-4/D-6, Stripe D-12 |
| **Tests may not pin a bug** | When a finding is "test pins wrong behaviour", the story's first commit *changes the test to the correct expectation* (red), then fixes the code. | Stripe D-2, Mollie T-H1, PayPal T-1 |

## Branching and merge order (all sprints)

- Branch per sprint and repo: `b-7.4.x-VR-<sprint>-<slug>`, cut from `b-7.4.x`. No worktrees.
- **payment-base merges first.** Provider branches pin payment-base to the sprint branch in CI while in
  review; the pin is reverted in the provider branch before merge (same procedure as GRAPH-QL / CI GraphQL
  matrix). A payment-base release tag bumps the composer `branch-alias` in the same commit.
- Consumer test counts (stripe, mollie-payment, paypal, one-page-checkout, opalreturns) before/after a
  payment-base change are recorded in the sprint's `done/` report; an unexplained difference blocks merge.
- Merge only on the owner's approval. After each finished story: update `status.md`, write the `done/`
  report.
