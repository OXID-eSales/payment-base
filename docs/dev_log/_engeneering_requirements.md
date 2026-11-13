# Engineering requirements — payment-base

These rules apply to every sprint in `docs/dev_log/*/sprints/`. A sprint doc references this file instead of
repeating the principles, and names only what is specific to its subject. A story is not done until its tests,
user-visible proof and quality gates are complete.

This file combines the per-day rule sets that came before it
([`20260826`](20260826/sprints/_engeneering_requirements.md) →
[`20260827`](20260827/sprints/_engeneering_requirements.md) →
[`20260903`](20260903/sprints/_engeneering_requirements.md) →
[`20261030`](20261030/sprints/_engeneering_requirements.md)) with the cross-module rules in
[`../engineering/shared-requirements.md`](../engineering/shared-requirements.md) and the invariants in
[`../invariants/`](../invariants/) and [`../engineering/money-arithmetic.md`](../engineering/money-arithmetic.md).
Where they disagree, this file wins for payment-base; `shared-requirements.md` stays the source for the PSP modules.

## Repositories and branches

| Part                     | Repository                          | Path                                   | Branch rule                                                                                       |
| ------------------------ | ----------------------------------- | -------------------------------------- | ------------------------------------------------------------------------------------------------- |
| payment-base (this)      | OXID-eSales/payment-base            | `source/extensions/payment-base`       | branch `b-7.4.x-<topic>` (or `b-7.4.x-<EPIC>-<NN>-<slug>`) from `b-7.4.x`; the owner merges         |
| Stripe                   | OXID-eSales stripe module           | `source/extensions/stripe`             | consumer: twin branch from `b-7.4.x` only when the sprint changes its side; pins pb in CI          |
| PayPal                   | OXID-eSales paypal module           | `source/extensions/paypal`             | as Stripe                                                                                         |
| Mollie                   | mollie-payment                      | `source/extensions/mollie-payment`     | as Stripe                                                                                         |
| one-page-checkout, opalreturns | their own repositories        | `source/extensions/…`                  | consumers; not changed unless the sprint names a story there                                      |
| OXID core                | oxideshop-ce                        | `source/` (vendor)                     | never changed; payment-base extends through `metadata.php` `extend`, events and DI only           |

- Never commit on `b-7.4.x` itself; it is main. Feature branch → green CI → **ask the owner** before the `--no-ff` merge.
- Never push to a main branch, never fast-forward or pull other checkouts without an explicit ask, never use git worktrees.
- **payment-base merges first.** Provider CI installs payment-base from `b-7.4.x`; while in review a provider branch
  pins payment-base to the sprint branch, and the pin is reverted in the provider branch before its merge.
- A release tag bumps the composer `branch-alias` and `CHANGELOG.md` in the same commit.

## Core requirements

| Principle                          | Application |
| ---------------------------------- | ----------- |
| **TDD-first**                      | A failing PHPUnit (or Playwright) test before production code; red → green → refactor. A bug fix starts with a regression test. When a test pins wrong behaviour, the first commit changes the test to the correct expectation (red), then the code. |
| **Mandatory gates**                | `./bin/pre-commit-check.sh --full` → `Status: COMMITABLE` before every commit: `composer phpcs` (PSR-12), `composer phpstan` (level max), `composer phpmd`, `composer dead-code`, `composer dead-assets`, PHPUnit Unit + Integration. CI (`styles`, `dead-code`, `isolated_unit_tests`, `integration_tests` on OXID 7.1–7.6, `gitleaks`) runs the same and must be green. No `--no-verify`. |
| **Never suppress static analysis** | No `@SuppressWarnings`, no baseline growth (PHPMD, PHPStan, dead-code baselines only shrink). `@phpstan-ignore-next-line` only for OXID core patterns (virtual `*_parent`, `oxNew`, `Registry::*`). Complexity gets refactored; thresholds stay. |
| **Proven through the UI**          | A behaviour a shopper or merchant triggers by clicking is not done until a Playwright test has clicked it (payment-base's UI is proven through a consumer's suite, e.g. `extensions/stripe/tests/e2e/playwright`). Unit tests and DB reads are supporting evidence, never the proof. Headless/GraphQL behaviour is proven through the client call. |
| **Contract-first, one path**       | "Place Order" creates a contract; the order exists only when the contract is fulfilled (`DRAFT → PENDING → READY_TO_COMMIT → COMMITTED → FULFILLED`). Events dispatch, handlers mutate; controllers only orchestrate. One commit path; no second, parallel way to reach the same state. |
| **One path, no fallbacks**         | A required collaborator is required: `@`, never `@?`; no `?X $x = null` that switches to a legacy ladder; no `?? 'EUR'`, `?? contract amount`, `'unknown_payment'`, "degraded fallback". A misconfiguration fails at container boot or with a 500, never as a quiet second code path. |
| **Agnosticism**                    | No `\Stripe\*`, `\PayPal\*`, `\Mollie\*`, no provider-name literals. Every shop-level feature works in a shop with **no PSP module installed** (e.g. on `oxidinvoice`). Provider specifics enter through tagged services (`oe.payment.admin_panel`, `oe.payment.event_translator`, …) and `ProviderEventTranslatorInterface`. |
| **Additive only**                  | payment-base is consumed by stripe, paypal, mollie-payment, one-page-checkout and opalreturns. Public API changes are append-only with a safe default and a kill switch. Consumer test counts are recorded before/after; an unexplained difference blocks the merge. |
| **OXID totals win**                | payment-base never changes an OXID total and never extends `Price`, `PriceList`, `Basket`, `BasketItem` ([invariant](../invariants/oxid-totals-win.md)). On the PSP wire money is integer minor units via `MinorUnitConverter` ([money-arithmetic](../engineering/money-arithmetic.md)). |
| **SOLID / Clean Code**             | One service per use case; controllers and class extensions decide *when*, collaborators decide *what*. OXID models/controllers have no constructor DI: resolve interfaces through `ContainerFactory` at that boundary only, never `new`, never a Doctrine class by name. Extensions extend `*_parent`, call `parent::`, return the same types (LSP). DTOs and events immutable. Methods 15–25 lines, early returns, no `else`, intention-revealing names, comments explain *why*. |
| **DRY**                            | Code that is not PSP-specific lives in payment-base, once. Reuse existing collaborators (`releaseVouchers()`, `CaptureRefundTracker`, core session keys) instead of writing a second copy of their SQL or state. Two similar blocks are fine; the third is extracted. |
| **Dead-code gate**                 | No class, service, method or `fnc` endpoint without a production caller; tests do not keep code alive. Fix a finding by deleting the code or giving it a caller, never by baselining it. Mechanics: [Dead-code gate](#dead-code-gate-how-it-works) below. |
| **No overengineering**             | Build for the sprint in hand: no priority configs, no heuristics, no plugin loaders, no feature flags for unshipped code, no `@deprecated` window — renames and removals happen in one commit with callers, tests, docs and language keys. |

## Security rules

1. **The PSP proposes, payment-base validates, the contract decides.** State moves only through the contract state
   machine; a provider event that does not fit the current state is refused, never forced (`payment_authorized`
   fulfils only from `pending`).
2. **Strict 1:1 order ↔ contract.** No capture, refund or commit without a contract; a PSP id is never taken from the
   request when the contract already names it. Contract rows use optimistic concurrency (`OXVERSION`); a stale write
   throws, it does not overwrite.
3. **Money moves are idempotent by PSP id.** Every capture/refund/chargeback is booked by its provider id (unique key,
   insert first, amount only if inserted). Outbound mutating PSP calls carry an idempotency key unique per *attempt*.
   `captured ≤ authorized` and `refunded ≤ captured` are enforced in the aggregate, not per provider.
   `OXCAPTUREDAMOUNT` is the source of truth for money taken ([invariant](../invariants/captured-amount.md)).
4. **Webhooks never lose a delivery.** Claim by event id (insert-first on `UNIQUE(OXEVENTID)`), one guard chain,
   catch `Throwable` at the process boundary and release the claim on failure so the PSP retry is processed.
   Signature verification stays in the provider module.
5. **Entry points are bounded.** Admin actions run only in an authenticated admin session with the action's rights,
   re-checked server-side. Storefront/`fnc`/GraphQL/MCP endpoints validate input, are rate-limited per visitor and
   write no rows for unknown plugins or ids. Return-leg URLs follow the return-URL policy; no open redirect, no IDOR
   (a return leg only ever touches the contract of its own session).
6. **Secrets stay sealed.** Client config is sealed; keys and tokens never reach templates, logs, exceptions or
   commits (`gitleaks` in CI).
7. **Templates:** Twig autoescape; no `|raw` on PSP or shopper text; en/de language files with identical keys; no
   inline scripts from external hosts.
8. **Logs and audit carry identifiers only** — contract id, order id, PSP ids, event id/type, SQLSTATE, HTTP status,
   reason codes. Never rendered mails, request/response bodies, addresses, e-mail addresses or tokens
   (`contract_token`, `cardToken`, `client_secret`, access tokens).
9. **Deploy is a tested artefact.** Order: payment-base → providers → OPC → `oe-payment-base-migrate` →
   `oe:module:…` → rebuild `var/cache/container` → warm-up → traffic. `oe-payment-base-schema-check` fails the deploy,
   not the first order. Every migration is additive and re-runnable.

## Where the rules come from (lessons from the 2026-10-30 violations review)

These rules were written because they were broken. A reviewer who sees the pattern on the left blocks the merge.

| Rule | What was found | Findings (see [`20261030/reports/`](20261030/reports/)) |
| ---- | -------------- | ------------------------------------------------------- |
| One path, no fallbacks | optional collaborators (`@?`) silently switching to a legacy ladder; `?? 'EUR'`, guessed amounts | pb C2/S3, Mollie D-H1/D-M2, PayPal Q-2/C-2/SEC-5, Stripe D-4/D-11 |
| Strict 1:1 order ↔ contract | capture/refund without a contract; PSP id read from the request | Stripe S-1/D-8, PayPal SEC-1 |
| Money moves idempotent by PSP id | double-booked refunds, idempotency key per contract instead of per attempt | Mollie S-H1/S-M4/S-M6, PayPal SEC-3/SEC-4/C-3, Stripe S-4, pb S4/S5 |
| Money invariants in the aggregate | each provider re-implementing `refunded ≤ captured` differently | pb S6/D1/D4 |
| Catch `Throwable` at process boundaries | an `\Error` left the webhook claim or a lock held forever | pb S1, Stripe S-3, Mollie S-M5, PayPal SEC-2 |
| Logs carry ids, never content | rendered mails, request bodies, tokens in logs | pb C4, Mollie request body, Stripe L-3, PayPal §4.2 |
| Deploy is a tested artefact | pay1 left half-migrated; the first order found the missing column | pay1 §4.1 of all reports |
| Dead-code gate | classes and endpoints that only tests kept alive | pb C1/D10, PayPal C-4/D-6, Stripe D-12 |
| Tests may not pin a bug | tests asserting the wrong behaviour, so the fix looked like a regression | Stripe D-2, Mollie T-H1, PayPal T-1 |

## Dead-code gate (how it works)

CI job `dead-code` next to `styles` (sprint 17, story DC-5):

- `composer dead-code` runs `shipmonk/dead-code-detector` through `phpstan-dead-code.neon` over `src/` and
  `migration/data/`. **Tests are not a usage source.** Framework callers are named once in
  `tests/PhpStan/DeadCode/OxidEntryPointUsageProvider.php` (GraphQLite attributes, `metadata.php` events, the
  `src/Eshop/` extend chain, admin controllers).
- `composer dead-assets` runs `bin/dead-assets`: language keys, Twig templates and `assets/` files without a reference.
  What a consumer repository uses goes in `dead-assets.allow` (path + reason).
- `tests/PhpStan/dead-code-baseline-*.neon` only **shrink**; a baseline line is removed with the code it covers. Each
  file's header says what it holds (`mcp-acp`, `consumer-api`, `kept`).
- The PR template carries the checkbox "new code has a production caller".

## Test strategy

- **Unit (`tests/Unit`):** services, resolvers, state transitions, DTO/event mapping, money arithmetic, guards; OXID
  boundaries through seams (`newOrder()`, `sessionBasket()`, …), interfaces mocked — not final classes.
- **Integration (`tests/Integration`):** real DB, Doctrine repositories, migrations, DI container construction, event
  chains end to end (contract → event → handler → shop state), concurrency (stale contract, double webhook), metadata
  guards (`MetadataExtendTest`).
- **Multi-version:** the same suites pass on OXID 7.1–7.3 and 7.4–7.6 in CI; a feature that cannot run on a version
  says so in the sprint and in the gate, not by a silent skip.
- **Consumers:** stripe, paypal, mollie-payment, one-page-checkout, opalreturns suites run against the sprint branch;
  counts before/after go into the `done/` report.
- **UI / client e2e (Playwright):** every shopper or merchant flow the sprint changes is clicked through in a
  consumer's Playwright suite (mocked PSP where possible, sandbox for the money proof); headless flows through the
  GraphQL/MCP client. Screenshots for UI sprints.

## Pre-sprint checklist

Every sprint plan answers before work starts:

1. Which failing test drives the first commit?
2. Which rule or invariant above is load-bearing?
3. What is deliberately **not** in scope?
4. What gets deleted? (No net code growth without a reason.)
5. Which consumers are affected, and do they need a twin branch?
6. What is the rollback if activation or migration fails on a production shop?

## Required completion evidence

Each sprint: plan moved to `done/sprint-NN-<KEY>.md` (or the plan stays in `sprints/` and links its done report), a
report `done/sprint-NN-<KEY>.md` (branch, commits, commands, test counts incl. consumers, gate output
`Status: COMMITABLE`, CI links, screenshots for UI sprints, changed files, limitations, anything not verified),
lessons in `reports/NN-….md`, `status.md` of the day updated, `CHANGELOG.md` entry for anything that ships.
