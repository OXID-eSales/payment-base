# Mythos O-08: the validation endpoint writes unlimited rows into oe_payments_idempotency

Date: 2026-11-13. A report only. No code was changed.

## Source

- Reported on 2026-11-11 by the internal security check "Mythos", finding O-08 in report
  `bericht-wstg-one-page-checkout.md` v0.3 (module OnePage Checkout on CE 7.6).
- Affected versions: oxid-esales/payment-base v1.7.4 (commit 1092209), used by oxid-esales/onepage-checkout
  b-7.4.x commit bbea1fc3, on CE b-7.6.x commit 7581098 (instance sec-760).
- Rating in the report: low. Next step: CVSS-B. WSTG 4.10.5.
- Status in the report: remeasured on 2026-11-11 with an own script and a control run, not yet handed to
  development. Checked against the PST board by title on 2026-11-11: no matching epic.
- Open in the report: other payment-base versions. See "Other versions" below. The code is the same up to
  v1.8.0-rc1.

## Finding as reported

Every call of `cl=oepaymentvalidationapi&fnc=validate` stores a row in `oe_payments_idempotency`. That table
also protects payments against double execution. Invented plugin IDs also get a row. The limit of 30 calls
per minute counts per (plugin ID, session), so a new plugin ID or a new session starts again at zero.

The report expects the limit to apply per visitor and to count only valid plugin IDs.

Measured on 2026-11-11 by a visitor without login (with a session, the form token and the shop's Origin):

| Run | Result |
|---|---|
| Control: 40 calls, one existing plugin ID, one session | HTTP 500 before the limit, first HTTP 429 at call 31, **1** new row |
| Test: 40 calls, 40 invented plugin IDs, same session | no HTTP 429, **40** new rows |
| Test: 5 fresh sessions right after the limit was reached | HTTP 500 ×5, no 429 |

On this instance a call that passes the limit ends in HTTP 500, so here 500 means "not limited".
Appendix D of the report also lists the HTTP 500 itself as a defect without security effect (for Mantis).

Repro: `php repro-2026.11-046.php --shop https://<name>.test [--instance <sdk path>] [--cacert <file>]`.
Cleanup: the same command with `--cleanup`. Earlier script: `o07_payment_base_validation_unbounded_rows.py`.
Both scripts are attached to the Mythos report and are not in this repo.

## Cause in the code (checked on b-7.4.x, HEAD d25a286 = v1.8.0-rc1)

1. **The rate limit runs before the allowlist.** The guards are sorted ascending by priority
   (`ValidationApiController::sortGuards`, `services.yaml` around line 844). The order is
   PostOnly 10 → PayloadSize 20 → ActiveSession 30 → SameOrigin 40 → Csrf 50 → **RateLimit 60** →
   **PluginIdAllowlist 70**. `RateLimitGuard::check()` calls `store->increment()` first. The allowlist runs
   later, so an unknown plugin ID has already written its row before it is rejected.

2. **The key includes plugin ID and session.** `RateLimitGuard::buildKey()` builds
   `validate:{pluginModuleId}:{sessionId}:{floor(time/60)}`. The plugin ID comes from the request. Each new
   value, session or minute gives a new key, and `DoctrineRateLimitStore::increment()` inserts a new row for
   it (`insertRow`, OXID `md5($key . microtime())`). There is no per-visitor (IP) part and no limit across
   all keys.

3. **Expired rows are never removed.** `DoctrineRateLimitStore` sets `OXEXPIRES` to +60 s. It only checks
   expiry when the same key is read again, which never happens after the minute is over.
   `DoctrineIdempotencyRepository::deleteExpired()` has no caller in payment-base. Its only caller is the
   **Stripe** console command `stripe:prune-idempotency` (Sprint 133 F8), which runs only if the merchant
   schedules it in cron. Mollie and PayPal have no cleanup. (Corrected 2026-11-13: the first version of this
   report said "no caller".) This explains the 20 rows from earlier days that the reporter still found on
   sec-760 (Stripe installed, most likely no cron). Without that cron the rows are not only unlimited in
   number but also permanent.

4. **Why invented IDs give 500 and not 422.** `PluginIdAllowlistGuard` should answer 422. It calls
   `OxidActiveModuleQuery::isActive()` → `ModuleActivationBridge::isActive()` →
   `ModuleStateService::isActive()` → `moduleConfigurationDao->get($moduleId, …)`, which throws for an
   unknown module ID. Nothing catches the exception, so the shop answers 500. For active IDs without
   shipped rules (`oe_payment_base`, and per the report the two active modules), `FilesystemValidationRuleLoader`
   fails with "Validation rules file not found", which is also 500. This is the appendix-D defect.
   Points 4 and 1 are separate: even with a working 422, the row is already written.

5. Side note: `ConfigurableRateLimitConfig` is wired with a fixed `$globalDefault: 30`. The admin setting
   `iValidationApiRatePerMinute` is not read (see the comment in `services.yaml`).

## Impact

Unlimited and permanent growth of `oe_payments_idempotency` for anyone with a session, its stoken and the
shop's Origin, which any visitor has. The table is shared with webhook, checkout-attempt and admin-operation
idempotency, so its size affects lookups on the payment path. There is no data exposure and no bypass of a
payment guard. The rating "low" fits; CVSS-B is still to be calculated.

## Other versions

`git diff 1092209 HEAD -- src/Validation` is empty, so v1.7.4 through v1.8.0-rc1 have the same validation
code and the same behaviour. Older tags were not checked.

## Fix direction (proposal, not approved)

The fix should be one straightforward path with no fallbacks:

- Move `PluginIdAllowlistGuard` before `RateLimitGuard` (priority below 60), so unknown IDs never reach the
  store.
- Make `OxidActiveModuleQuery::isActive()` return `false` for an unknown module ID, so the allowlist answers
  422 as intended and not 500.
- Have the allowlist accept only modules that are registered as payment plugins and ship validation rules,
  not any active module. That also removes the 500 for `oe_payment_base`.
- Key the limit per visitor (client IP via `TrustedProxyClientIp`, as the webhook limiter does) in addition
  to, or instead of, the session.
- Remove expired `rate_limit` rows: delete expired rows on insert, or call `deleteExpired()` from a
  defined place. Better still, move the limiter out of the idempotency table into its own table.
- Read the admin setting `iValidationApiRatePerMinute` and drop the hard-coded 30.

Tests needed: a unit test for the guard order (allowlist before rate limit), a unit test for an unknown ID
giving 422 without a store write, and an integration test that N calls with N invented IDs write 0 rows.

## Next steps

- Owner decision: open a PST epic/ticket (none exists) and approve the fix direction.
- Mythos: CVSS-B score.
- Appendix-D 500 → Mantis.

## Size estimate at 10,000 visitors a day (added 2026-11-13)

**Measured on the local DB (317 rows):** data + indexes = 262 KB, so about **0.8 KB per row** on disk (`UNIQUE(OXKEY)`,
`IDX_EXPIRES`, `IDX_ORDER_OPERATION`, PK). Lifetime (`OXEXPIRES − OXCREATED`) per row kind: `rate_limit` 1–2 min,
`order_create` 2 min, admin capture/refund and `capture`/`refund_charge` 24 h, **`paypal_webhook` 90 days**.

**Assumed traffic** (not measured; change the inputs and the numbers scale linearly): 10,000 visitors/day, 2.5 %
conversion = **250 orders/day**, 2 Pay clicks per order (retries, abandoned attempts) = 500 validation calls/day,
about 4 webhook events per order, 30 % of orders via PayPal.

| Rows per day | Today (no cron) | Source |
|---|---|---|
| Validation `rate_limit` | ~500 | one per (plugin, session, minute) with a Pay click |
| Webhook `rate_limit` | ≤ 1,000 | one per (provider, PSP IP, minute) with an event |
| Payment rows (order_create, capture, refund, admin ops, PayPal webhook claims) | ~750 | ~3 per order |
| **Total, honest traffic** | **~2,250 rows ≈ 1.8 MB/day** | |

| Scenario | Rows | Size |
|---|---|---|
| Today, no cleanup, honest traffic, 1 year | ~820,000 | **~0.65 GB**, growing for good |
| Today, O-08 abuse: one client at 20 req/s, 1 day | ~1.7 million | **~1.4 GB per day**, limited only by the client |
| After sprint 29, `rate_limit` rows (inline purge) | live rows of the last 1–2 min only: ≤ 2 × clients active per minute (≈ 100 at this traffic) | < 0.1 MB |
| After sprint 29, abuse | ≤ 1 live row per client IP and minute, purged after it expires; N attacking IPs → ~2N rows | N × 1.6 KB |
| After sprint 29, payment rows with the daily `oe:payments:idempotency:prune` | 24 h rows: ~1 day's worth (~500); PayPal webhook claims: 75 orders × 3 events × 90 days ≈ 20,000 | **~17 MB steady state** |

Conclusion: at this traffic the table does not hurt an honest shop today; it grows by about 0.65 GB a year. The
risk is abuse. One client fills it at about 1.4 GB a day, and nothing ever removes the rows. After the sprint,
the table stays at about 20 MB regardless of abuse, and 90 % of that is PayPal's 90-day webhook retention.
Whether 90 days is needed there is a PayPal question, not part of this sprint.

## Local reproduction (sprint 29 S0, 2026-11-13)

The Mythos script was not available; probed the real container services in the local shop's PHP container instead
(payment-base `b-7.4.x-validation-rate-limit` = v1.8.0-rc1 code, CE of this SDK checkout):

| pluginModuleId | `ModuleActivationBridge::isActive` | rules file | validation run |
|---|---|---|---|
| `oe_payments_stripe_wallet` | true | yes (`vendor/oxid-esales/stripe-wallet`) | `validateField` returns, no exception |
| `oe_payments_mollie` | true | yes | returns, no exception |
| `oe_payment_base` | true | **no** | → "Validation rules file not found" → 500 (problem 6) |
| `oe_one_page_checkout` (not installed here) | **throws** `ModuleConfigurationNotFoundException` | — | → 500 (problem 5) |
| `cc29probe-x1` (invented) | **throws** `ModuleConfigurationNotFoundException` | — | → 500 (problem 5) |

**R1:** locally Stripe's validation path works end to end, so no Stripe story is needed. The appendix-D 500s
for "the two active modules" match problems 5/6: `oe_payment_base` ships no rules, and an ID without module
configuration makes `isActive` throw. Open for Mythos: which two module IDs were sent. If one was
`oe_payments_stripe_wallet` on sec-760, that instance's Stripe install lacks `src/Resources/validation-rules.php`.

A hand-rolled curl session through the Cloudflare tunnel (`daniil.oxiddev.de`) got no `sid` cookie and stopped at
`ActiveSessionGuard` (401), so the HTTP-level measurement moves to S6. Until then, S1's integration test on the real
guard chain stands in for it.

## After sprint 29 (measured 2026-11-13, local shop, HTTPS through its tunnel)

Branch `b-7.4.x-validation-rate-limit`. Same three steps as the Mythos script (`repro29.sh`: browser User-Agent, session
from a product page, Origin of the shop):

| Step | Before (Mythos, v1.7.4) | After |
|---|---|---|
| 1 control: 40 calls, existing plugin id, one session | 500 before the limit, first 429 at call 31, 1 row | **30 × 200 (`valid:true`), first 429 at call 31, 1 row** |
| 2 test: 40 invented plugin ids, same session | no 429, **40 rows** | **40 × 422, 0 rows** |
| 3 test: 5 fresh sessions after the limit | 5 × 500 (not limited) | **5 × 429** |
| `oe_payment_base` (active, no rules) | 500 | 422 |
| refused value (`firstName=Jo<script>`) for stripe / mollie | — | 200 `valid:false`, `blocked_character "<"`, formatted message |

The window's one row: `validate:{sha1(address)}:{minute}`, count 45 = 40 + 5 fresh sessions. After the first inserts
on the branch the table held **0 expired rows** (it had 223, among them rows from 2026-10-07); what remains is live:
160 PayPal webhook rows inside their 90-day life and the current rate-limit row.

**Deployment finding:** the local shop's address in the key is the tunnel's (Docker gateway 172.18.0.1), because
`sPaymentBaseTrustedProxies` is empty. Behind a reverse proxy or CDN without that setting, all visitors share one bucket.
Both checkout footers fail open on 429 (the server-side check stays the gate), so checkout keeps working; only the early
field feedback stops once the shared limit is reached. CHANGELOG "Upgrade" and README say to set the trusted proxies.

**Size estimate corrected:** no current code writes `paypal_webhook` rows (the 90-day rows are leftovers of older PayPal
code), so they leave as they expire. At 10,000 visitors a day the table now holds about one day of 24-hour rows plus a few
minutes of 2-minute rows: **~1,000 rows ≈ 1 MB**, under abuse as well (one live rate-limit row per client address).
