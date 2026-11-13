# Sprint 29 (VRL) S3 — count per visitor, limit from the admin setting

**What changed (payment-base)**
- `RateLimitGuard` key `validate:{sha1(clientIp)}:{minute}` (was plugin id + session); `'unknown'` without an address.
- `ValidationRequestContext`: required `clientIp` (D2), `getClientIp()`; `fromRequest(TrustedProxyClientIp $resolver, …)`
  resolves it from `REMOTE_ADDR` / the trusted proxy header, as the webhook limiters do.
- `ValidationApiController::init()` takes `TrustedProxyClientIp` from the container and fails without it (no silent path).
- `RateLimitConfigInterface::getLimitForPlugin(string)` → `getLimitPerMinute()` (the limit no longer depends on the plugin;
  keeping the argument would leave an unused parameter). `ConfigurableRateLimitConfig` (+ test) deleted;
  `ModuleSettingRateLimitConfig` reads `iValidationApiRatePerMinute` through `ModuleSettingBridgeInterface` (a saved num
  setting is a string), and fails on a value that is not a positive number.
- `services.yaml`: literal `$globalDefault: 30` and the "deferred to Phase E" note removed. Setting label en/de: "per visitor address and minute".

**mollie-payment** branch `b-7.4.x-validation-rate-limit` (from b-7.4.x 21166a0): `ValidationEndpointGuardParityTest` passes the
client address and mocks `getLimitPerMinute()` (two lines). Unit suite 923 ✓ against this payment-base.

**Red → green:** `RateLimitGuardTest` (6); integration `ValidationGuardChainTest::testInventedPluginIds_AreRejected_AndWriteNoRows`
(40 × 422, **0 rows**) and `::testFreshSessions_FromOneClient_AreLimited` (429 at limit + 1). New:
`ValidationRequestContextTest` ×3 (address resolution), `ValidationApiControllerTest::testInitWithoutTheClientAddressResolver_Fails`,
integration `ModuleSettingRateLimitConfigTest` (string/int, 5 misconfigurations).

**Gates:** phpcs ✓ · phpstan ✓ · phpmd ✓ · dead-code ✓ · dead-assets ✓ · Unit 2105: only `DoctrineRateLimitStoreTest` (S4) red.
