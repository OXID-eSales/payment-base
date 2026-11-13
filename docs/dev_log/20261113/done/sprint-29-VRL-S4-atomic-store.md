# Sprint 29 (VRL) S4 — one atomic increment, expired rows reused or removed, fail closed

**What changed**
- `DoctrineRateLimitStore::increment()` = one `INSERT … ON DUPLICATE KEY UPDATE` on `UNIQUE(OXKEY)` (OXRESULT assigned
  before OXEXPIRES, so an expired row of the same key restarts at 1), then one `SELECT OXRESULT`. `findRow/isExpired/
  insertRow/updateRow` and the `catch (Exception) { return 0; }` are gone: a DB error fails the request (D3), for the
  validation endpoint and the three webhook limiters alike.
- When the upsert inserted (affected rows = 1), the store calls `IdempotencyRepositoryInterface::deleteExpired()`, the
  table's one purge (made automatic and bounded in S5). Wiring: `$records` in `services.yaml`.
- OXID of a rate-limit row = `md5(key)` (deterministic; was `md5(key . microtime())`).

**Red → green:** `DoctrineRateLimitStoreTest::testADatabaseError_IsNotTakenAsUnderTheLimit`; integration
`testExpiredRows_AreRemovedOnTheNextNewKey`, `testAnExpiredKeyIsReused_AndStartsAtOne`. New unit tests: a new row purges,
a counted row does not, the increment is one upsert (no insert/update calls).

**Gates:** phpcs ✓ · phpstan ✓ · phpmd ✓ · dead-code ✓ · Unit 2108 ✓ · Integration (VRL + claim tests) 16 ✓ ·
stripe `WebhookGuardChainWiringTest` ✓ (stripe's other 36 unit errors are the same set on plain b-7.4.x: standalone run
without `source/config.inc.php`, not from this branch).
