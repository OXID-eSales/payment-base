# Sprint 13 — a failed webhook delivery must stay retryable (payment-base)

**Date:** 2026-09-24 · **Branch:** `b-7.4.x-webhook-retry-after-failed-claim` · **Ticket:** (id tbd, follow-up of MOL-17)
**Status:** IN PROGRESS — merge only on approval. Twin plan: mollie-payment
`docs/dev_day_log/20260924/sprints/webhook-retry-after-failed-claim.md`.

## Problem
`AbstractWebhookProcessor::process()` claims the event id before processing (INSERT on `UNIQUE(OXEVENTID)`).
A delivery that ended `failed` (PSP answered non-2xx, will retry) kept the claim, so the retry was answered
`skipped('Already processed')` with 200 — the failure was final by construction, and the row did not even
carry the error text.

## Change
- `DoctrineWebhookLogRepository::claimEvent()`: on the unique-key violation, one guarded
  `UPDATE … SET OXSTATUS='claimed', OXRECEIVEDAT=now, OXPROCESSEDAT=NULL, OXERROR=NULL WHERE OXEVENTID=? AND
  OXSTATUS='failed'`; 1 row → claimed. Processed and in-flight rows stay exclusive. Atomic: two concurrent
  retries cannot both win.
- `AbstractWebhookProcessor`: the failure reason (exception message or `WebhookResult::$error`) is stored on
  the row via `updateStatus()`.
- Interface docblock states the semantics. No schema change, no retry counter; the PSP owns the schedule.

## Proof
- Unit `tests/Unit/Repository/DoctrineWebhookLogRepositoryClaimTest` (insert / re-claim / refuse).
- Mollie integration `tests/Integration/Webhook/DoctrineIdempotencyClaimTest`: `failed` row re-claimable and its
  error cleared; `processed` and `claimed` rows refused.

## Out of scope
A `claimed` row left by a crashed process still blocks forever; a time-based re-claim is a separate decision.
