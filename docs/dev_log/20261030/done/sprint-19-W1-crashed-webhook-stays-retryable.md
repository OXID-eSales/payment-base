# Sprint 19 — W1 (payment-base): a webhook delivery that crashed stays retryable

**Branch:** `b-7.4.x-VR-19-webhook-claim` (from `b-7.4.x` @ `d062955`) · **Date:** 2026-11-03 · **Commit:** `69de450`
**Status:** done on the branch, not pushed, awaiting merge approval. Touches `AbstractWebhookProcessor` like
`b-7.4.x-VR-17-dead-code` (different hunks: VR-17 removes two methods + the 3rd ctor arg, VR-19 changes the catch).

## Change

- `AbstractWebhookProcessor::process()`: `catch (Exception)` → `catch (Throwable)`. A `TypeError` (pay1 2026-10-30:
  stale container, 43 Stripe webhook 500s in 2 h) now ends the delivery `failed` instead of leaving it `claimed`.
- `DoctrineWebhookLogRepository::claimEvent()`: on the unique-key hit, one guarded UPDATE re-claims a `failed` row **or**
  a `claimed` row older than `STALE_CLAIM_SECONDS` (600) — the fatal-error / timeout case sprint 13 left out of scope.
  Fresh claims and processed rows stay exclusive; two concurrent retries cannot both win.

## Red → green

- Unit: `AbstractWebhookProcessorTest::testAnErrorInProcessEventMarksTheDeliveryFailedSoTheRetryCanReclaimIt`;
  `DoctrineWebhookLogRepositoryClaimTest::{testARetryReclaimsAFailedOrAStaleClaimedRowInOneGuardedStatement,
  testAClaimOlderThanTenMinutesCountsAsStale, testAReplayOfAProcessedOrInFlightDeliveryIsRefused}` (replace the two
  `update()`-based tests). 3 red before, green after.
- Integration (real table): `DoctrineWebhookLogRepositoryReclaimTest` — fresh claim exclusive, 9 min still in flight,
  11 min taken over (and then exclusive again), failed re-claimed, processed refused. 4/4 green.

## Gates

- Unit 1978 OK (9 skipped) · phpstan no errors · phpcs clean · phpmd clean.
- Integration, full suite from the branch: 238 tests, failures = the 7 local baseline failures of `b-7.4.x` (GraphQL
  admin, environment). Run with a namespace-only autoloader bootstrap (shop libraries, payment-base classes from the
  branch); kept outside the repo.

## Follow-ups

- W2–W6 (shared guard chain, PayPal adoption, delete provider copies) — next in sprint 19.
- Low: `AbstractWebhookProcessor` still returns `$e->getMessage()` to the PSP (`WebhookResult::failure`) — sprint 18 L1.
