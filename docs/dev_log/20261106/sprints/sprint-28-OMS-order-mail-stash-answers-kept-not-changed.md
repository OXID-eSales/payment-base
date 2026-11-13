# Sprint 28 — OMS: `stash()` answers "are the mails kept", not "did bytes change"

**Engineering requirements (binding):** [`dev_log/_engeneering_requirements.md`](../../_engeneering_requirements.md)  
**Date:** 2026-11-06 · **Epic:** OMS (order mail stash, STRP-176 follow-up) · **Status:** IMPLEMENTED 2026-11-06 — commits 4d66a14 (plan), 0cf7d29 (S1 red), e3ad212 (S2), S3 see `../done/sprint-28-OMS.md`; gates green; awaiting owner's no-ff merge into `b-7.4.x`
**Repos:** payment-base only
**Branch:** `b-7.4.x-order-mail-stash` (created from `b-7.4.x` 7b53ad1, v1.7.2)
**Requirements:** [`../../20261030/sprints/_engeneering_requirements.md`](../../20261030/sprints/_engeneering_requirements.md)
— TDD (red first), SOLID, DRY, Clean Code, no overengineering, no fallbacks, tests may not pin a bug.
**Source:** pay1 incident 2026-11-06 14:37–14:39 (contracts `9f7660af…`, `dd46c4c6…`, orders 2154/2155), diagnosed in
the session of the same day; mechanism reproduced on the local shop against the real
`ContractOrderMailStoreInterface` service.

**Definition of Done (sprint-level):** a shopper who starts a Stripe Checkout Session three times on one
basket (two rewrites of the same early order) reaches Stripe on every attempt; the integration test that
re-stashes byte-identical mails is green on a real MySQL/MariaDB connection.

## Problem

| # | Finding | Where (b-7.4.x 7b53ad1) | Why it matters |
|---|---|---|---|
| 1 | `DoctrineContractOrderMailStore::stash()` returns `executeStatement(UPDATE …) === 1`. MySQL and MariaDB report **changed** rows, not matched rows, unless the connection sets `PDO::MYSQL_ATTR_FOUND_ROWS`; OXID's connection does not. A second stash of byte-identical mails on a row that is already `pending` changes nothing → `0` → `false`. | `src/Repository/DoctrineContractOrderMailStore.php:43-52` | `OxidShopOrderService::keepRewrittenOrderMailsOnContract()` (VR-16 O5) refuses the rewrite when the stash answers `false` → `ShopOrderException('order_mails_not_kept')` → Stripe `createCheckoutSession` 500 "Payment processing failed". The order mails carry no date or time, so the **second rewrite** of an unchanged basket always re-renders identical bytes: every third attempt on one contract fails, and every retry after it. Verified on pay1 (both stuck contracts: two replaced attempts, mail state `pending`, identical bodies) and locally (first stash `true`, identical second `false`, changed body `true`, identical again `false`). |
| 2 | The unit test `testStash_WritesTheMailsAsPendingAndAnswersWhetherTheRowExists` asserts `affected 0 → false` with no row in the picture. | `tests/Unit/Repository/DoctrineContractOrderMailStoreTest.php:41` | It pins the wrong contract: "0 changed rows" is not "no row". Per *tests may not pin a bug* the first commit changes the expectation (red), then the code. |

Not a finding, kept as is: the strictness of VR-16 O5 ("an order whose mails cannot wait on its contract is
refused, not mailed at once"). The service is right to refuse; the store was wrong about what happened.

## Design

`stash()` keeps its signature and its one UPDATE. Its answer becomes *"the contract row now holds exactly these
mails as pending"*:

```
changed = UPDATE … WHERE OXID = :id            -- as today
return changed === 1
    || row exists WHERE OXID = :id AND OXORDERMAILSTATE = 'pending'
                    AND OXORDERMAILS = :mails AND OXORDERMAILSENTAT IS NULL
```

- The common path (first stash, or a rewrite that changed anything) stays one statement.
- `0` is disambiguated by one SELECT that checks the full intended state, not just existence — so a row in
  `sending`/`sent` or with other mails still answers `false`, exactly as before. No behaviour widens.
- Rejected: `PDO::MYSQL_ATTR_FOUND_ROWS` (shared OXID connection, affects every repository);
  a "stashed at" column to force a change (migration + a column nobody reads → YAGNI);
  tolerating `false` in the service (a silent second path, forbidden by *one path, no fallbacks*).

DRY inside the store: `read()` and the new check both fetch one row under the same failure wrapping, so the
try/catch moves into one private `fetchRow(sql, params, what): array|false`; `read()` keeps its shape on top
of it. `write()` and `failure()` are reused unchanged. No new class, no new interface, no new service wiring.

## Out of scope

- The Stripe module's `isOfReplacedAttempt` logging the expected OPC-175 session expiry at **error** level
  (stripe repo; separate one-story sprint).
- A `ShopOrderException` extractor for `ThrowableSummary` so `getErrorCode()` reaches the log (payment-base,
  diagnostic; would have made this a five-minute diagnosis — own sprint, needs the VR-18 "ids not content" review).
- Deploying to pay1 and un-sticking its two contracts: they recover by themselves on the next attempt once this
  is deployed (the stash then answers `true`).
- The two other `write() === …` call sites (`claim()`, `markSent()`): their UPDATEs always change the state
  column, so changed-rows semantics is correct there. Left alone; noted in the done report.

## Risks & unknowns

- The integration suite runs against the local shop DB (`oe_payments_contract` exists there); the regression
  test seeds and deletes its own row by a fixed id, like `ContractLostUpdateTest`.
- Comparing a ~37 KB `OXORDERMAILS` text in a WHERE clause: latin1 column, plain-ASCII JSON (class docblock),
  equality on the primary-key row only — one row compared, no scan. No index needed.

---

## Story 1 — Pin the correct contract: an identical re-stash is kept

**Why:** the regression has to be red on a real connection before the code moves (TDD; *tests may not pin a bug*).
**Estimate:** S

**Tests first (TDD):**
- `tests/Integration/Repository/DoctrineContractOrderMailStoreRestashTest.php` (`#[Group('database')]`)
  - `testStash_OfByteIdenticalMailsOnAPendingRow_IsKept` — stash, stash again with the same mails → both `true`,
    row still `pending`, `OXORDERMAILS` unchanged. **Red** on 7b53ad1 (second call answers `false`).
  - `testStash_OfChangedMailsOnAPendingRow_ReplacesThem` — second stash with a different body → `true`, row holds the new body.
  - `testStash_OnAnUnknownContract_IsNotKept` — unknown id → `false`.
- `tests/Unit/Repository/DoctrineContractOrderMailStoreTest.php`
  - change `testStash_WritesTheMailsAsPendingAndAnswersWhetherTheRowExists` → the `affected 0` half becomes
    explicit: with `fetchAssociative` answering `false` (no such row) the stash answers `false`.
  - `testStash_WhenNothingChangedButTheRowAlreadyHoldsExactlyTheseMails_AnswersTrue` — `affected 0`,
    `fetchAssociative` answers a row; asserts the SELECT names `OXORDERMAILSTATE = :pending`, `OXORDERMAILS = :mails`,
    `OXORDERMAILSENTAT IS NULL` and binds the same encoded mails as the UPDATE.
  - `testStash_WhenTheKeptCheckFails_ThrowsWithoutTheMailContent` — `fetchAssociative` throws a DBAL exception →
    `RuntimeException` naming the contract and the SQLSTATE, never the recipient (VR-18 "ids not content").

**Implementation steps:**
1. Add the three integration tests; run them → one red.
2. Change the unit expectation and add the two unit tests → red.
3. Commit as "Sprint 28 (OMS) S1: tests — an identical re-stash is kept (red)".

**SOLID/Clean check:**
- SRP: the tests describe one responsibility of the store: answering whether the mails are on the row.
- DRY: fixture style copied from `ContractLostUpdateTest` (own id, delete in setUp/tearDown); the unit helper
  `connection(affected:)` is reused, `fetchAssociative` configured per test.

**DevOps gate:** red tests only; `composer phpcs` on `tests/` is not part of the gate (phpcs covers `src/`).

**Definition of Done:** the integration test fails on the unchanged store with "second stash answered false".

---

## Story 2 — `stash()` answers whether the mails are kept

**Why:** closes Problem 1 with one statement on the common path and one SELECT on the ambiguous one.
**Estimate:** S

**Tests first (TDD):** Story 1's tests, now going green.

**Implementation steps:**
1. `stash()`: encode once; `$changed = $this->write(...)`; `return $changed === 1 || $this->isKept($contractId, $encoded);`.
2. `isKept(string $contractId, string $mails): bool` — SELECT `OXID` with the four-way WHERE; `!== false`.
3. `fetchRow(string $sql, array $params, string $what): array|false` — the try/catch from `read()`; `read()` and `isKept()` call it.
4. Docblock on `stash()` (why: changed-rows semantics; one sentence, no restating of code).
5. Gates, then commit "Sprint 28 (OMS) S2: stash() answers whether the mails are kept, not whether bytes changed".

**SOLID/Clean check:**
- SRP: `DoctrineContractOrderMailStore` stays the one owner of the order-mail columns.
- DIP: still depends on `Doctrine\DBAL\Connection` and `ThrowableSummaryInterface` only.
- LSP: `ContractOrderMailStoreInterface::stash()` contract ("true when the mails wait on the contract") is now
  met in every case it was meant to cover; no signature change, additive-only public API honoured.
- DRY: `write()`, `failure()`, `encode()` reused; `fetchRow()` replaces the second copy of the read try/catch.
- Clean: no `else`; each method ≤ 15 lines; booleans `isKept`.

**DevOps gate:**
- `composer phpcs` ✓ · `composer phpstan` ✓ (level max, no new suppressions) · `composer phpmd` ✓ · `composer dead-code` ✓
- `php vendor/bin/phpunit -c phpunit.xml --testsuite Unit` ✓
- `php vendor/bin/phpunit -c vendor/oxid-esales/payment-base/tests/phpunit-integration.xml --group database` ✓ (shop context)

**Definition of Done:** Story 1's tests green; the local stash probe (first `true`, identical second `true`) confirms it end to end.

---

## Story 3 — Record it

**Why:** CHANGELOG, module version, done report, status line (branching-and-merge rules).
**Estimate:** S

**Tests first (TDD):** none — documentation and version only.

**Implementation steps:**
1. `CHANGELOG.md`: `## [v1.7.3 - 2026-11-06]` → *Fixed (OMS-28)* with the mechanism, the symptom and the pay1 reference. **D1:** patch bump (behaviour fix, no API change) — owner may re-tag.
2. `metadata.php` version `1.7.3`.
3. `docs/dev_log/20261106/done/sprint-28-OMS.md` with commits, gate evidence, consumer test counts (stripe) before/after.
4. `docs/dev_log/20261106/status.md` one line.
5. Commit "Sprint 28 (OMS) S3: CHANGELOG v1.7.3, metadata 1.7.3, done report".

**DevOps gate:** `composer dead-assets` ✓ (no asset touched; run for the record).

**Definition of Done:** `status.md` names the branch, the fix and the merge state; the owner can merge no-ff into `b-7.4.x`.

---

## Suggested order
1. Story 1 — the red integration test is the proof that the mechanism, not a theory, is being fixed.
2. Story 2 — the fix.
3. Story 3 — record and hand over for merge.
