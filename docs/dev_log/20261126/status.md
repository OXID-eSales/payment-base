# Status 2026-11-26 — payment-base

Branch `b-7.4.x-uncaught-exception-STRP-182` (from `b-7.4.x` 3f417ef); stripe twin branch of the same name (tests only).
Plan: [`done/sprints/sprint-01-STRP182-draft-contract-return-refused.md`](done/sprints/sprint-01-STRP182-draft-contract-return-refused.md)

| Story | State | What landed | Tests |
|---|---|---|---|
| S0 red chain test (Mythos repro) | done | draft test red with the Mythos exception, control green | 2 tests (1 red → green in S2, 1 green) |
| S1 handler refuses a draft | done | dead DRAFT→PENDING branch removed; explicit refusal naming the contract, nothing saved | handler test 6/6 green |
| S2 return leg refuses a draft | done | `refusesADraft()` before both dispatches: null + error log (ids only), no event; S0 green | responder test 20/20 green |
| S3 webhook leg refuses a draft | done | draft joins the terminal refusal: `refused(contract_draft)` + error log, no event | commit-service test 12/12 green |
| S4 proof and record | done | gate COMMITABLE; consumers compared with baseline; stripe fixtures fixed on twin branch | Unit 2165 green; integration = baseline |

Open: owner merge (payment-base and stripe, any order, no pin); D1 — Mythos rerun of `repro-2026.11-123.php` after the merge.
