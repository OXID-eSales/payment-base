# Sprint 29 — VRL: the validation rate limit counts per visitor and writes no rows for unknown plugins

**Engineering requirements (binding):** [`dev_log/_engeneering_requirements.md`](../../_engeneering_requirements.md)  
**Date:** 2026-11-13 · **Epic:** VRL (validation rate limit, Mythos O-08) · **Status:** DONE 2026-11-13 — D1–D6 as recommended, D7/D8 superseded (automatic purge); see `../done/sprint-29-VRL-S*.md`; awaiting owner merges
**Repos:** payment-base (all code). mollie-payment: one test line, only if D2 = "required". stripe: deprecate or
Feature branch only. stripe: no change (D7 superseded).
**Branch:** `b-7.4.x-validation-rate-limit` (to be created from `b-7.4.x` d25a286, v1.8.0-rc1)
**Requirements:** [`../../20261030/sprints/_engeneering_requirements.md`](../../20261030/sprints/_engeneering_requirements.md)
— TDD (red first), SOLID, DRY, Clean Code, no overengineering, one path / no fallbacks, tests may not pin a bug,
additive-only public API, dead-code gate.
**Source:** Mythos report `bericht-wstg-one-page-checkout.md` v0.3, finding O-08 (rating low), and the analysis in
[`../reports/01-mythos-o08-validation-endpoint-unbounded-rate-limit-rows.md`](../reports/01-mythos-o08-validation-endpoint-unbounded-rate-limit-rows.md).

**Definition of Done (sprint-level):** on the local shop, the Mythos repro script `repro-2026.11-046.php` (or its
equivalent integration test, S1) shows: 40 calls with 40 invented plugin IDs → HTTP 422 and **0** new rows;
5 fresh sessions from the same client after the limit → HTTP 429; no `rate_limit` row older than two minutes
remains after the next counted call. All gates green in payment-base, plus the stripe and mollie unit suites
against the branch.

## Problem

| # | Finding | Where (b-7.4.x d25a286) | Why it matters |
|---|---|---|---|
| 1 | The rate limit (priority 60) runs **before** the plugin allowlist (70). `RateLimitGuard::check()` writes a row first; the allowlist rejects afterwards. | `services.yaml` ~844–943, `ValidationApiController::sortGuards` | Each invented `pluginModuleId` writes one row (O-08 test 2: 40 calls → 40 rows). |
| 2 | The key is `validate:{pluginModuleId}:{sessionId}:{minute}`. Both parts come from the caller; a new plugin ID or a new session starts again at 0. | `RateLimitGuard::buildKey()` | The limit does not limit a visitor (O-08 test 3: 5 fresh sessions, no 429). |
| 3 | `rate_limit` rows are never removed by the limiter. Expiry is only checked when the same key is read again, which never happens after its minute. The **webhook** limiters of stripe/mollie/paypal use the same store, so their rows (one per provider, IP and minute) pile up too. | `DoctrineRateLimitStore`, `Webhook\Guard\RateLimitGuard` | `oe_payments_idempotency`, the table that protects payment operations, grows at the pace of anonymous traffic (the reporter found rows from earlier days). |
| 9 | The only cleanup of the whole table is Stripe's `stripe:prune-idempotency` → `deleteExpired()` (Sprint 133 F8), and only if the merchant puts it in cron. A shop with only Mollie and/or PayPal has **no** cleanup. Payment rows (checkout attempts, webhook claims, admin operations) and rate-limit rows of every provider wait on a Stripe command. | `stripe/src/Stripe/Command/PruneIdempotencyCommand.php`; `DoctrineIdempotencyRepository::deleteExpired()` | The table is payment-base's, so its housekeeping belongs there (agnosticism). Deleting expired rows is safe: `claim()` already treats an expired row as free and overwrites it (`UPDATE … WHERE OXEXPIRES < :now`). |
| 4 | `increment()` is find-then-insert on a table with `UNIQUE(OXKEY)`, and `catch (Exception) { return 0; }`. Two concurrent first calls → the second insert violates the key → `0` → passes. Any DB error → `0` → unlimited. | `DoctrineRateLimitStore::increment()` | The control fails open, contrary to *one path, no fallbacks* ("a security control that cannot be built fails the request", Sprint 133 F4). |
| 5 | `OxidActiveModuleQuery::isActive()` calls `ModuleConfigurationDao::get()`, which throws for an unknown module ID → uncaught → **HTTP 500** instead of the allowlist's 422. | `OxidActiveModuleQuery` | Appendix-D defect; also hides problem 1 (no clear 422 for invented IDs). |
| 6 | An active module that ships no `src/Resources/validation-rules.php` (`oe_payment_base`, OPC) passes the allowlist and ends in `FilesystemValidationRuleLoader` → `InvalidArgumentException` → **HTTP 500**. | `FilesystemValidationRuleLoader::loadFor()` | Appendix-D defect. |
| 7 | The limit is wired as a literal `$globalDefault: 30`; the admin setting `iValidationApiRatePerMinute` (metadata, group `validation`, labels in en/de) is never read. | `services.yaml` ~922 | The merchant setting does nothing. |
| 8 | `RateLimitGuardTest::testCountersAreScopedByPluginModuleId` asserts the behaviour of problem 2. | `tests/Unit/Validation/Guard/RateLimitGuardTest.php:52` | *Tests may not pin a bug* → changed first (red). |

Not a finding, kept as is: the guards before 60 (POST, size, session, same-origin, CSRF). They already require a
shop session, its `stoken` and the shop's Origin; O-08 does not get past them, it only uses them cheaply.

## Design

```
Guard order (ascending):  PostOnly 10 → PayloadSize 20 → ActiveSession 30 → SameOrigin 40 → Csrf 50
                          → PluginIdAllowlist 53 → ValidationRules 56 → RateLimit 60
```

1. **Reject before counting.** `PluginIdAllowlistGuard` moves to priority 53 and a new
   `ValidationRulesGuard` (56) answers 422 when the plugin ships no rules file. Only a request for an active
   plugin that has rules reaches the counter. Invented IDs write nothing.
   - `OxidActiveModuleQuery::isActive()` answers `false` for an unknown ID: it asks
     `ModuleConfigurationDaoInterface::exists($id, $shopId)` (present in CE, checked 2026-11-13) before
     `get($id, $shopId)->isActivated()`. It takes the DAO instead of `ModuleActivationBridgeInterface`, the same DAO
     `OxidPluginPathResolver` already uses. No catch: any other exception still propagates.
   - `ValidationRulesGuard(PluginPathResolverInterface)`: `file_exists(root . FilesystemValidationRuleLoader::RULES_RELATIVE_PATH)`.
     The constant becomes `public` so the path exists once (DRY). A separate guard, not a second constructor
     argument on the allowlist and not a new method on `ValidationRuleLoaderInterface`: mollie's tests construct
     `PluginIdAllowlistGuard($activeModuleQuery)` and implement `ValidationRuleLoaderInterface` anonymously, and both
     must stay source-compatible (additive-only public API).
2. **Count per visitor.** Key `validate:{sha1(clientIp)}:{minute}`, with no plugin ID and no session. The client IP
   comes from `TrustedProxyClientIp` (the resolver the webhook controllers already use, settings group
   "Webhooks"), resolved in `ValidationApiController::buildRequestContext()` and carried on
   `ValidationRequestContext::getClientIp()`. Sizing: the shop JS calls the endpoint only on a Pay click
   (`stripe-footer.html.twig` `_interceptSubmit`, mollie same pattern), not per keystroke, so 30 per minute per IP
   is far above what a NAT'ed office of shoppers produces.
3. **Limit from the admin setting.** `ModuleSettingRateLimitConfig` implements the unchanged
   `RateLimitConfigInterface::getLimitForPlugin(string)` by reading `iValidationApiRatePerMinute` through
   `ModuleSettingServiceInterface` (pattern: `VoucherReleaseSettings`). It ignores the argument, and its docblock says
   so. `ConfigurableRateLimitConfig` and the literal 30 are deleted (dead-code gate). The signature stays, so mollie's
   parity mock keeps working.
4. **One atomic statement, expired rows reused or removed, fail closed.** `DoctrineRateLimitStore::increment()`:
   ```sql
   INSERT INTO oe_payments_idempotency (OXID, OXKEY, OXORDERID, OXOPERATION, OXRESULT, OXSTATUS, OXCREATED, OXEXPIRES)
   VALUES (:id, :key, '', 'rate_limit', '1', 'rate_limit', :now, :expires)
   ON DUPLICATE KEY UPDATE
     OXRESULT  = IF(OXEXPIRES <= :now, '1', CAST(OXRESULT AS UNSIGNED) + 1),
     OXEXPIRES = IF(OXEXPIRES <= :now, :expires, OXEXPIRES)
   ```
   then `SELECT OXRESULT … WHERE OXKEY = :key`. When the insert created the row (affected rows = 1), it calls the
   shared purge of §5 (`IdempotencyRepositoryInterface::deleteExpired()`), once per new key, i.e. once per
   client and minute.
   DBAL exceptions are **not** caught any more: the request fails with 500 (validation and webhooks alike; PSPs
   retry webhooks). `RateLimitStoreInterface` is unchanged (stripe's wiring test implements it).
   MySQL/MariaDB only, like the shop.
5. **The table cleans itself on every insert, with no cron and no CLI** (owner question 2026-11-13: "is the table
   cleaned up automatically, always, without CLI?"; replaces the earlier D7/D8):
   - `IdempotencyRepositoryInterface::deleteExpired()` (exists) becomes
     `DELETE FROM oe_payments_idempotency WHERE OXEXPIRES < :now LIMIT 500` (on `IDX_EXPIRES`) and no longer
     swallows DBAL exceptions (no fallbacks).
   - It runs after every **insert** into the table: `DoctrineIdempotencyRepository::save()` (insert branch) and
     `::claim()` (insert branch), and `DoctrineRateLimitStore::increment()` when its upsert inserted (the store now
     takes `IdempotencyRepositoryInterface`; there is one purge, not two). Each order, webhook event, admin operation
     and new rate-limit window therefore removes up to 500 expired rows. The table stays at about its live size, and
     an old backlog (a year without cleanup ≈ 800k rows) is worked off in steps of 500 instead of one long DELETE
     in a shopper's request.
   - Safe for every reader, checked 2026-11-13: `claim()` takes over an expired row, `IdempotentAttemptGuard` and
     stripe's `IdempotentExecutor` treat an expired row as absent, `release()` writes an already-expired row on
     purpose ("no attempt"), and `OperationIdempotency` re-claims. A deleted row and an expired row behave the same.
     No current code writes `paypal_webhook` rows (the 90-day rows in the local DB are leftovers from older PayPal
     code), so they go as they expire.
   - Stripe's `stripe:prune-idempotency` stays as it is: a manual tool, no longer needed in cron. No stripe change.

Rejected:
- A separate rate-limit table: needs a migration and a second store for an unbounded-growth problem that the purge
  already solves. Its only benefit is not sharing the table with payment idempotency. Revisit if the purge shows
  up in slow logs.
- APCu (`Mcp/Http/ApcuRateLimiter`): per PHP-FPM pool, not shared across web nodes, so it is not a limit.
- Keeping the session in the key: O-08 test 3 shows a session costs nothing.
- Catching missing rules in the controller (`InvalidArgumentException` → 422): exception-as-control-flow, and it
  would also turn a *malformed* rules file (a real misconfiguration that must stay 500) into 422.
- A cron/console purge: one more moving part to deploy and forget; the inline purge is bounded and local.

## Decisions for the owner (review these first)

| # | Question | Recommendation |
|---|---|---|
| D1 | Key: client IP only, or client IP **and** session? | IP only. The session adds nothing an attacker can't reset. |
| D2 | `ValidationRequestContext` gets `clientIp`. As a **required** 9th constructor argument (mollie's `ValidationEndpointGuardParityTest::context()` needs one line, on `b-7.4.x-validation-rate-limit` in mollie-payment), or trailing `string $clientIp = ''` (no consumer change, but a default a rule forbids)? | Required + the one-line mollie change. |
| D3 | Store fails closed (DB error → 500, also for the three webhook endpoints) instead of today's silent pass. | Yes. Matches Sprint 133 F4 and the "no fallbacks" rule. |
| D4 | Purge inline on insert (this plan), or a separate table? | Inline purge; table only if it shows up in slow logs. |
| D5 | Version: CHANGELOG entry under the coming `v1.8.0` (b-7.4.x is at `v1.8.0-rc1`), so the fix ships in the release, not as v1.7.5. A v1.7.x backport only on request. | Into v1.8.0. |
| D6 | Response for `oe_payment_base` / OPC (active, no rules): 422 (this plan). | 422. |
| D7 | ~~payment-base prune command, stripe's deprecated~~ **Superseded 2026-11-13:** no CLI. The table cleans itself on every insert (§5). Stripe's command stays untouched as a manual tool. | — |
| D8 | ~~inline purge for rate-limit rows plus cron~~ **Superseded 2026-11-13:** one automatic purge of all expired rows (§5). | — |

## Out of scope

- CVSS-B scoring and the reply to Mythos (security team; the done report gives them the new measurement).
- Mantis ticket for appendix D (problems 5/6 are fixed here; the ticket can reference this sprint).
- The Stripe-ID 500 on sec-760 if S0 shows it is an instance problem (e.g. an older stripe-wallet without
  `validation-rules.php`): a report line, no code.
- Moving `TrustedProxyClientIp` / its settings out of the `Webhook` namespace and group: naming only; a follow-up if wanted.
- `ValidationRequestContext::extractSessionId()` reading `session_id()` and not the cookie: works; not touched.

## Risks & unknowns

- **R1 — Why did `oe_payments_stripe_wallet` answer 500 on sec-760?** Locally the module source
  (`vendor/oxid-esales/stripe-wallet`) ships `src/Resources/validation-rules.php`. If it 500s locally too, Stripe's
  Pay-click validation is broken for shoppers, and that becomes a story of its own. S0 answers it before S2.
- **R2 — resolved 2026-11-13:** `ModuleConfigurationDaoInterface::exists(string, int): bool` is on the CE DAO
  in this checkout. Still to check in S2: that it is also on CE b-7.6.x (sec-760); if not, S2 catches exactly
  `ModuleConfigurationNotFoundException`.
- **R3 — `ON DUPLICATE KEY UPDATE` evaluation order.** In MySQL, assignments run left to right and later ones see
  earlier results, so `OXRESULT` must be assigned **before** `OXEXPIRES` (as written). S4's integration test covers
  the "expired row is reused and starts at 1" case on the real DB.
- **R4 — Shared NAT / CGNAT.** Covered by the Pay-click-only call pattern and the admin setting; documented in the
  setting's help text (en/de).
- **R5 — Local live repro.** On 2026-11-13 a curl session against `daniil.oxiddev.de` never got a `sid` cookie
  (Cloudflare tunnel; HttpOnly cookies), so the endpoint answered 401 before any guard of interest. S0 uses the
  Mythos script or Playwright, not hand-rolled curl.

---

## Story 0 — Spike: reproduce locally and answer R1

**Why:** prove the mechanism on our shop before moving code, and decide whether Stripe's 500 is ours.
**Estimate:** S (timebox 1 h)

**Steps:**
1. Get `repro-2026.11-046.php` from the Mythos report. Run it against the local shop (cookie/session setup as the
   script does it; `--cacert` for the tunnel if needed). Record the three step results and the row counts.
2. Same session, one call with `pluginModuleId=oe_payments_stripe_wallet` and a real billing field. Record the
   status and the shop log line.
3. Clean up with `--cleanup`.

**Definition of Done:** a short section "Local reproduction" appended to `../reports/01-…md` with statuses, row
counts and the R1 answer. If Stripe 500s locally: stop and raise a new story before S2.

---

## Story 1 — Pin the correct contract (red)

**Why:** *tests may not pin a bug*; the regression must be red on a real connection first.
**Estimate:** S

**Tests first (TDD):**
- `tests/Unit/Validation/Guard/RateLimitGuardTest.php`
  - `testCountersAreScopedByPluginModuleId` → **replaced** by `testOneClientShares_OneCounter_AcrossPluginIdsAndSessions`
    (same IP, two plugin IDs, two sessions → the third call over a limit of 2 is 429). Red.
  - `testDifferentClients_HaveOwnCounters`. Red (no IP on the context yet → compile/arg error counts as red).
  - `testRespectsPerPluginOverride` → deleted (the override was removed in VR-24; the test only exercised a mock).
- `tests/Unit/Validation/Guard/PluginIdAllowlistGuardTest.php`
  - `testHasPrioritySeventyenty` → `testRunsBeforeTheRateLimit` (`< RateLimitGuard::getPriority()`). Red.
- `tests/Unit/Validation/Guard/OxidActiveModuleQueryTest.php` (new): unknown ID → `false`, no exception. Red.
- `tests/Unit/Validation/Guard/ValidationRulesGuardTest.php` (new): no rules file → 422; file present → null;
  priority between the allowlist and the rate limit. Red (class missing).
- `tests/Integration/Validation/ValidationGuardChainTest.php` (new, `#[Group('database')]`), on the **container's**
  tagged guards in controller order with a real `DoctrineRateLimitStore`:
  - `testInventedPluginIds_WriteNoRows` (40 IDs → 40×422, row delta 0). Red (today 40 rows).
  - `testFreshSessions_FromOneClient_AreLimited` (31st call with a new session each time → 429). Red.
  - `testExpiredRows_AreRemovedOnTheNextNewKey` (seed an expired `rate_limit` row, an expired payment row and a
    live payment row → the next new key removes both expired rows and keeps the live one). Red.
  - `testAnExpiredKeyIsReused_AndStartsAtOne` (seed same key expired, count 99 → next increment = 1). Red.
  - Fixtures: keys prefixed `vrl29-`, removed in setUp/tearDown (pattern of `ContractLostUpdateTest`).

**Implementation steps:** add/change the tests; run; commit "Sprint 29 (VRL) S1: tests — limit per visitor, no
rows for unknown plugins, expired rows removed (red)".

**DevOps gate:** red tests only.

**Definition of Done:** each listed test fails for the reason in its name, not for a fixture error.

---

## Story 2 — Reject unknown plugins and plugins without rules before counting

**Why:** closes problems 1, 5 and 6.
**Estimate:** S

**Implementation steps:**
1. `OxidActiveModuleQuery`: inject `ModuleConfigurationDaoInterface` (replaces the activation bridge in
   `services.yaml`); `isActive()` = `exists()` && `get()->isActivated()` (R2).
2. `FilesystemValidationRuleLoader::RULES_RELATIVE_PATH` → `public const`.
3. `ValidationRulesGuard` (new, `final`, priority 56): `PluginPathResolverInterface` + the constant; 422.
4. `services.yaml`: allowlist priority 70 → 53; register `ValidationRulesGuard` with tag priority 56; update the
   section comment ("eight-guard chain", order listed once).
5. Guard docblocks: "Priority 53/56 — before the rate limit, so a rejected request writes no row."

**SOLID/Clean check:** SRP — one guard, one question; OCP — a new tagged guard, no change to the controller
loop; LSP — `ActiveModuleQueryInterface::isActive()` now honours its contract (bool, no throw) for every input;
DRY — one rules path constant.

**DevOps gate:** `composer phpcs` · `composer phpstan` (max, no new suppressions) · `composer phpmd` ·
`composer dead-code` · Unit ✓ · S1's allowlist/rules/active-query tests green.

**Definition of Done:** `testInventedPluginIds_WriteNoRows` green; `oe_payment_base` → 422.

---

## Story 3 — Count per visitor, limit from the admin setting

**Why:** closes problems 2, 7 and 8.
**Estimate:** M

**Implementation steps:**
1. `ValidationRequestContext`: `clientIp` (D2) + `getClientIp()`; `fromRequest(…, string $clientIp)`.
2. `ValidationApiController::buildRequestContext()`: resolve `TrustedProxyClientIp` from the container (as the
   webhook controllers do) with `REMOTE_ADDR` and the request headers; a resolver that cannot be built fails the
   request (no `?->` fallback).
3. `RateLimitGuard::buildKey()` → `validate:` . `sha1($ip)` . `:` . bucket (`'unknown'` only if the server gave no
   `REMOTE_ADDR`, same as the webhook guard). The class docblock is updated.
4. `ModuleSettingRateLimitConfig` (new) reads `iValidationApiRatePerMinute`; delete `ConfigurableRateLimitConfig`
   and its test; `services.yaml` drops `$globalDefault: 30` and the "deferred to Phase E" comment.
5. Setting help text (en/de): "per visitor (IP address) and minute".
6. If D2 = required: mollie-payment branch `b-7.4.x-validation-rate-limit` from `b-7.4.x`, one-line change to
   `ValidationEndpointGuardParityTest::context()`; owner merges (other-repos rule).

**SOLID/Clean check:** DIP — guard depends on `RateLimitConfigInterface`, controller on the container service;
SRP — IP resolution stays in `TrustedProxyClientIp` (reused, not copied); no `else`, methods ≤ 15 lines.

**DevOps gate:** all payment-base gates; `stripe` and `mollie-payment` unit suites against the branch (path repo)
green.

**Definition of Done:** `testOneClientShares_OneCounter…`, `testDifferentClients…`,
`testFreshSessions_FromOneClient_AreLimited` green; changing the admin setting to 5 makes the 6th call 429 (integration).

---

## Story 4 — One atomic increment, expired rows reused or removed, fail closed

**Why:** closes problems 3 and 4, for the validation limiter and the three webhook limiters.
**Estimate:** M

**Implementation steps:**
1. `DoctrineRateLimitStore::increment()`: the upsert from Design §4, then the count read. When the upsert
   inserted, it calls `IdempotencyRepositoryInterface::deleteExpired()` (the one purge, made automatic in S5).
2. Remove `findRow`, `isExpired`, `insertRow`, `updateRow` and the `catch (Exception) { return 0; }`.
3. Class docblock: why the table is shared, the purge rule, fail-closed.
4. Unit test (mocked `Connection`): the statements and their order; a DBAL exception propagates.

**SOLID/Clean check:** SRP — the store counts and keeps its own rows tidy; LSP — `RateLimitStoreInterface::increment()`
keeps its contract and loses the hidden "0 on error"; no new interface or class.

**DevOps gate:** all gates; Integration `--group database` ✓; stripe `WebhookGuardChainWiringTest` ✓.

**Definition of Done:** `testExpiredRows_AreRemovedOnTheNextNewKey` and `testAnExpiredKeyIsReused_AndStartsAtOne`
green; two concurrent first calls (integration, two connections) give counts 1 and 2, not 1 and 0.

---

## Story 5 — The table cleans itself on every insert

**Why:** closes problem 9 without depending on a cron (design §5).
**Estimate:** S

**Tests first (TDD):**
- `tests/Integration/Repository/IdempotencyAutoPurgeTest.php` (`#[Group('integration')]`):
  - `testAnInsertBySave_RemovesExpiredRowsOfEveryKind`: seed expired rows of each kind (`rate_limit`, checkout
    attempt, admin operation, legacy `paypal_webhook`) and live ones → one `save()` of a new record → the expired
    rows are gone, the live ones stay.
  - `testAnInsertByClaim_RemovesExpiredRows`: the same through `claim()`.
  - `testAnUpdate_DoesNotPurge`: `save()` of an existing id leaves an expired row in place (the purge runs on inserts only).
  - `testOnePurgeRemovesAtMost500Rows`: seed 501 expired rows → one insert → one left.
  - `testAPurgedKeyIsClaimedLikeAFreshKey`.
- `tests/Unit/Repository/DoctrineIdempotencyRepositoryTest.php`: `deleteExpired()` lets a DBAL exception through
  (today it answers 0).

**Implementation steps:**
1. `deleteExpired()`: `LIMIT 500`, no catch; docblock.
2. `save()` / `claim()`: call it after their insert.
3. `DoctrineRateLimitStore`: inject `IdempotencyRepositoryInterface`; purge via it (replaces S4's own DELETE).
4. README: "The table cleans itself; no cron needed." CHANGELOG mentions that `stripe:prune-idempotency` is no longer
   needed in cron.

**SOLID/Clean check:** SRP: the repository owns its table's housekeeping. DRY: one purge for all writers. No new
class, command or interface.

**DevOps gate:** all payment-base gates; stripe unit suite (IdempotentExecutor, PruneIdempotencyCommand) green.

**Definition of Done:** on the local DB, after one order (or one validation call),
`select count(*) from oe_payments_idempotency where oxexpires < now()` decreases by up to 500 per insert and reaches 0.

---

## Story 6 — Prove it end to end and record

**Why:** the sprint DoD is measured with the reporter's script, not only with our tests.
**Estimate:** S

**Steps:**
1. Rerun S0's procedure on the local shop. Expected: step 1 first 429 at call 31; step 2 HTTP 422 × 40 and
   **0** new rows; step 3 HTTP 429 × 5; `select count(*) … where oxstatus='rate_limit' and oxexpires < now() - interval 1 minute` = 0
   after one more call.
2. Playwright smoke: Stripe and Mollie Pay click with an invalid city still shows the field error (the
   validation path still works for real plugins).
3. `CHANGELOG.md` under the coming v1.8.0 (D5): *Security (Mythos O-08)* and *Fixed (appendix D: 500 → 422)*.
4. `../done/sprint-29-VRL.md`: commits, gate evidence, before/after measurement, consumer suites. Section for the
   security team: the new numbers for CVSS-B.
5. `../status.md` one line.

**Definition of Done:** sprint-level DoD met; owner can merge no-ff into `b-7.4.x` (payment-base first, then
mollie (D2).

## Suggested order
S0 → S1 → S2 → S3 → S4 → S5 → S6. S2 alone already removes the row growth from invented IDs. S3 and S4 make the limit
real and the table bounded, so S2 can ship alone if the owner wants a quick first merge.
