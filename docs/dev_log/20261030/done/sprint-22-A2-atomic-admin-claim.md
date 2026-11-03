# Sprint 22 — A2 (payment-base): two admin capture / refund calls cannot both get a fresh claim

**Branch:** `b-7.4.x-VR-22-admin-money` (from `b-7.4.x` @ `d062955`) · **Date:** 2026-11-03 · **Commit:** `e72629d` (pushed)

## Change

- `IdempotencyRepositoryInterface::claim(IdempotencyRecord, DateTimeImmutable $now): bool` — `DoctrineIdempotencyRepository`:
  INSERT; on the primary-key hit one `UPDATE … WHERE OXID = :id AND OXEXPIRES < :now`. A released record already has its
  expiry backdated (`OperationIdempotency::release()`), so "ran out" covers both.
- `OperationIdempotency::claim()` → `records->claim()`; on a lost claim it reads the record only to answer COMPLETED vs
  IN_FLIGHT.
- `findByKey()` rethrows database errors (was: `null` = fresh). Stripe's `IdempotentExecutor` therefore fails the
  operation on a DB error instead of calling Stripe. The no-op `catch (Exception $e) { throw $e; }` in `save()` is gone.

## Red → green

- Unit `DoctrineIdempotencyRepositoryClaimTest` (insert / guarded take-over SQL / live record refused / DB error surfaces)
  — 4 red before. `DoctrineIdempotencyRepositoryTest::findByKeyReturnsNullOnException` pinned the swallow → removed.
- Integration `tests/Integration/Repository/DoctrineIdempotencyRepositoryClaimTest` (real table): claimed once while
  live; an expired record is taken over exactly once.
- The two in-memory fakes implement `claim()`; `OperationIdempotencyTest` / `AdminPaymentOperationsTest` unchanged and
  green (incl. the racing-insert case).

## Gates

Unit 1979 OK (9 skipped) · phpstan / phpcs / phpmd clean · integration full suite from the branch = the 7 local
baseline failures.

## Not done (next in sprint 22)

- Per-order lock across different client keys (`GET_LOCK('pb_order_…')`) — belongs to the `MoneyOperationRunner` (A3).
- A3 classic admin through the same runner as GraphQL (rights, step-up, audit, idempotency) and the capture/refund
  policy with narrow gateways — needs each provider's gateway (A5–A7); A1 tracker invariants after F4–F6.
