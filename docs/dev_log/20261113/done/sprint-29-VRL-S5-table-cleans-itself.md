# Sprint 29 (VRL) S5 — oe_payments_idempotency cleans itself on every insert (no cron, no CLI)

Changed after the owner's question "is the table being cleaned up automatically always without CLI?" (D7/D8 superseded).

**What changed (payment-base)**
- `IdempotencyRepositoryInterface::deleteExpiredBatch(): int` (new): `DELETE … WHERE OXEXPIRES < :now AND OXID <> :keep LIMIT 500`.
- `DoctrineIdempotencyRepository::save()` (insert branch) and `::claim()` (insert branch) purge one batch after the insert,
  sparing the row just written (what save() wrote, findByKey() returns, even a record saved already expired; the next
  insert removes it). `DoctrineRateLimitStore` purges one batch when its upsert inserted.
- `deleteExpired()` = batches of 500 until one comes back short (no single huge DELETE); no longer swallows DB errors
  (the old unit test `deleteExpiredReturnsZeroOnException` pinned that and was changed first).
- Test doubles `InMemoryIdempotency`, `InMemoryIdempotencyRepository`: `deleteExpiredBatch()`.

**stripe** branch `b-7.4.x-validation-rate-limit` (from b-7.4.x): `stripe:prune-idempotency` docblock/help/services comment
say "manual tool, no cron needed"; integration `DoctrineIdempotencyRepositoryTest::deleteExpiredRemovesOnlyExpiredRecords`
no longer asserts the purge count (saving the active record may already have removed the expired one) and keeps
asserting the end state (expired gone, active kept).

**Red → green:** integration `IdempotencyAutoPurgeTest` (7: save/claim purge every kind, update does not purge, one insert ≤ 500,
full purge in batches, purged key claimed fresh, expired save read back until the next insert); unit
`deleteExpiredLetsADatabaseErrorThrough`, `deleteExpiredRunsBatchesUntilOneComesBackShort`, `deleteExpiredBatchDeletesOneBatch`;
`DoctrineIdempotencyRepositoryClaimTest::testANewKeyIsClaimedByInserting` now expects exactly one bounded purge.

**Gates:** phpcs ✓ · phpstan ✓ · phpmd ✓ · dead-code ✓ · dead-assets ✓ · Unit 2110 ✓ · mollie unit 923 ✓ ·
stripe: unit Prune/IdempotentExecutor/WebhookGuardChainWiring ✓, integration DoctrineIdempotencyRepositoryTest 9 ✓.
Full payment-base integration suite: 405 tests, 9 errors + 1 failure, all environmental: graphql schema-cache unlink
race (different tests per run), local graphql-admin checkout out of step (`AuditChainRepositoryInterface` missing,
`StepUp::require` signature parity). None of them touch the idempotency table; CI is the judge for those.
