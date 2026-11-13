# Status 2026-11-13

## Sprint 29 — VRL (Mythos O-08): validation rate limit per visitor, no rows for unknown plugins, table cleaned

Branch `b-7.4.x-validation-rate-limit` (payment-base, from `b-7.4.x` d25a286 = v1.8.0-rc1). D1–D8 accepted as recommended.
Plan: [`sprints/sprint-29-VRL-…`](sprints/sprint-29-VRL-validation-rate-limit-per-visitor-no-rows-for-unknown-plugins.md) ·
Report: [`reports/01-mythos-o08-…`](reports/01-mythos-o08-validation-endpoint-unbounded-rate-limit-rows.md)

| Story | State | Notes |
|---|---|---|
| S0 local repro + R1 | done | problems 5/6 confirmed in the container; R1: Stripe validates fine locally, no extra story; HTTP measurement moved to S6 |
| S1 tests red | done | 5 unit + 2 integration files; all red for the named reason; OxidActiveModuleQueryTest moved to Integration (CE DAO not in standalone autoload) |
| S2 allowlist + rules guard before the limit | done | invented ids → 422 (allowlist 53), oe_payment_base → 422 (rules 56), no row; gates green |
| S3 per-visitor key + admin setting | done | 40 invented ids → 422, 0 rows; fresh sessions → 429; limit from iValidationApiRatePerMinute; mollie branch b-7.4.x-validation-rate-limit (2 test lines) |
| S4 atomic store, purge, fail closed | done | one upsert, expired key restarts at 1, new row purges expired rows, DB error propagates; gates green |
| S5 table cleans itself on every insert (no CLI) | done | every insert purges ≤ 500 expired rows (sparing its own row); deleteExpired batched, fails closed; stripe branch: test + docs |
| S6 prove + record | done | HTTP: 30×200 → 429 at 31; 40 invented ids → 422, 0 rows; fresh sessions → 429; 0 expired rows left; CHANGELOG/README |

**Sprint 29 DONE 2026-11-13** — awaiting owner review and no-ff merges, in this order: payment-base
`b-7.4.x-validation-rate-limit`, then mollie-payment `b-7.4.x-validation-rate-limit` (parity test, 2 lines), then stripe
`b-7.4.x-validation-rate-limit` (prune command docs + one integration assertion). Open: trusted proxies on production shops behind a
CDN; CVSS-B and the answer to Mythos (security team); Mantis for appendix D can reference this sprint.
