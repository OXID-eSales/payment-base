# payment-base — 2026-05-19 — Status

Branch: `b-7.4.x-agnosticism` (shared with opalreturns + stripe)

## Reports
- [03-payment-tab-spinner-blur-verification.md](reports/03-payment-tab-spinner-blur-verification.md) — Playwright verification of the admin Payment-tab spinner & blur-layer behaviour. Snapshot taken before the centralization sprint.
- [04-payment-tab-on-open-spinner-added.md](reports/04-payment-tab-on-open-spinner-added.md) — closed the on-tab-open + inter-order-navigation gap by adding a server-side busy class + cross-frame click listener + pagehide safety net. **Currently lives in stripe's panel template — Sprint 05 moves it.**

## Sprints
- ✅ ~~sprint-05~~ — **landed 2026-05-19**, see [done/sprint-05-completion-report.md](done/sprint-05-completion-report.md). Overlay machinery (markup + CSS + JS) moved from stripe panel into payment-base's outer template. PSP-agnostic class names (`pc-panel-busy`, `pc-spinner`, `js-payment-action-form`). 7 template-source guards (PB 899 → 906 tests); stripe panel shrunk by 73 LOC; old Sprint-107 Playwright spec deleted (subsumed). Three-module gate GREEN.

## Related (opalreturns dev_log)
- Sprints 01–04 (cumulative on the same branch): payment-base refund API + `RefundIntentEventInterface` + `RefundIntentHandler`; opalreturns consumes via published-language interfaces only. Architecture canary clean.
