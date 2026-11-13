# Status 2026-11-13

## Sprint 29 — VRL (Mythos O-08): validation rate limit per visitor, no rows for unknown plugins, table cleaned

Branch `b-7.4.x-validation-rate-limit` (payment-base, from `b-7.4.x` d25a286 = v1.8.0-rc1). D1–D8 accepted as recommended.
Plan: [`sprints/sprint-29-VRL-…`](sprints/sprint-29-VRL-validation-rate-limit-per-visitor-no-rows-for-unknown-plugins.md) ·
Report: [`reports/01-mythos-o08-…`](reports/01-mythos-o08-validation-endpoint-unbounded-rate-limit-rows.md)

| Story | State | Notes |
|---|---|---|
| S0 local repro + R1 | open | |
| S1 tests red | open | |
| S2 allowlist + rules guard before the limit | open | |
| S3 per-visitor key + admin setting | open | |
| S4 atomic store, purge, fail closed | open | |
| S5 `oe:payments:idempotency:prune` | open | |
| S6 prove + record | open | |
