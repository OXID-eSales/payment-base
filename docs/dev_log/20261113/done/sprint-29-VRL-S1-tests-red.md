# Sprint 29 (VRL) S1 — tests pin the correct contract (red)

| Test | Red because (on d25a286 code) |
|---|---|
| Unit `RateLimitGuardTest` (rewritten): one client shares one counter across plugin ids and sessions; different clients have own counters; the key carries nothing the caller chooses (sha1 of the IP) | `ValidationRequestContext` has no `clientIp` |
| Unit `RateLimitGuardTest::testRespectsPerPluginOverride`, `testCountersAreScopedByPluginModuleId` | **removed**: the first only exercised a mock (override removed in VR-24), the second pinned O-08 problem 2 |
| Unit `PluginIdAllowlistGuardTest::testRunsBeforeTheRulesGuardAndTheRateLimit` (was `testHasPrioritySeventyenty`) | priority is 70, expected 53 |
| Unit `ValidationRulesGuardTest` (new): no rules → 422, rules → pass, priority 56 | class missing |
| Unit `DoctrineRateLimitStoreTest` (new): a DBAL error propagates | store answers 0 ("under the limit") |
| Integration `OxidActiveModuleQueryTest` (new): unknown id → false without lookup; activated/deactivated | constructor still takes the activation bridge |
| Integration `ValidationGuardChainTest` (new, container guard chain after CSRF + real table): 40 invented ids → 422 and 0 rows; fresh sessions from one client limited | `clientIp` missing (S0 already showed the mechanism: row first, then `ModuleConfigurationNotFoundException`) |
| … `testExpiredRows_AreRemovedOnTheNextNewKey` | "Failed asserting that true is false" (old rows stay) |
| … `testAnExpiredKeyIsReused_AndStartsAtOne` | "0 is identical to 1" (unique-key violation swallowed → 0) |
| … `testALiveKeyCountsUp` | green (sanity) |

Deviation from the plan: `OxidActiveModuleQueryTest` lives in the Integration suite, since the standalone unit suite does not
autoload CE's `ModuleConfigurationDaoInterface`. The planned "two concurrent connections" test is replaced by
`testAnExpiredKeyIsReused_AndStartsAtOne`, which hits the same unique-key path deterministically; true concurrency is
not reproducible in PHPUnit without flakiness.

Commands: unit `docker compose exec php sh -c "cd extensions/payment-base && php vendor/bin/phpunit -c phpunit.xml --testsuite Unit"`;
integration `docker compose exec php php vendor/bin/phpunit -c vendor/oxid-esales/payment-base/tests/phpunit-integration.xml --filter …`.
