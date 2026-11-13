# Sprint 29 (VRL) S6 — proof over HTTP and record

- HTTP reproduction of the three Mythos steps on the local shop (HTTPS via its tunnel): control 30 × 200 then 429 at call 31;
  40 invented ids → 40 × 422 and 0 rows; 5 fresh sessions → 5 × 429; `oe_payment_base` → 422. Details and before/after table:
  `../reports/01-…md`, section "After sprint 29".
- Validation still works for real plugins: `firstName=Jo<script>` → `valid:false`, `blocked_character "<"`, formatted
  message, for stripe and mollie (HTTP level instead of the planned Playwright click: the footers send exactly this request;
  their fail-open handling was read in the templates).
- Table cleaned itself: 0 expired rows left after the first inserts (was 223).
- Deployment finding: behind a proxy/CDN without `sPaymentBaseTrustedProxies` all visitors share one bucket (local: tunnel
  address 172.18.0.1). Checkout unaffected (footers fail open), early feedback skipped after the shared limit. Documented in
  CHANGELOG "Upgrade" and README "Housekeeping and rate limits".
- CHANGELOG "Unreleased - for v1.8.0" (D5), README section, report updated incl. corrected size estimate (~1 MB at 10k visitors/day).

Deviations from the plan: Playwright smoke replaced by the HTTP-level check above; the "two concurrent connections" test
replaced by the deterministic expired-key test (S1); `OxidActiveModuleQueryTest` and `ModuleSettingRateLimitConfigTest` in the
Integration suite (CE classes not in the standalone unit autoload); D7/D8 superseded by the automatic purge (owner question).
