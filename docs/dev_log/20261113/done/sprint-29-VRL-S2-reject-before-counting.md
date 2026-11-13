# Sprint 29 (VRL) S2 — reject unknown plugins and plugins without rules before counting

**What changed**
- `OxidActiveModuleQuery` reads the module configuration (`ModuleConfigurationDaoInterface::exists()` then
  `get()->isActivated()`); an id the shop does not know is "not active" (was: `ModuleConfigurationNotFoundException` → 500).
- `PluginIdAllowlistGuard` priority 70 → **53**; new `ValidationRulesGuard` (**56**, 422 when the plugin ships no
  `src/Resources/validation-rules.php`); `FilesystemValidationRuleLoader::RULES_RELATIVE_PATH` public (one path, DRY).
- `services.yaml`: chain is now … CSRF 50 → allowlist 53 → rules 56 → rate limit 60.
- PHPStan bootstrap stubs: `ModuleConfigurationDaoInterface::exists()`, `ModuleConfiguration::isActivated()`; the now unused
  `ModuleActivationBridgeInterface` stub removed.

**Red → green:** `PluginIdAllowlistGuardTest` (3), `ValidationRulesGuardTest` (3), integration `OxidActiveModuleQueryTest` (3).

**Measured on the container's guard chain (local shop):** `vrl29-probe-a/b` → 422 by the allowlist; `oe_payment_base` →
422 by the rules guard; `oe_payments_stripe_wallet` passes; exactly 1 new `validate:` row (the Stripe call), 0 for the rejected ones.

**Gates:** phpcs ✓ · phpstan (max) ✓ · phpmd ✓ · dead-code ✓ · dead-assets ✓ · Unit 2102 tests: only the S3/S4 tests
of S1 still red (RateLimitGuardTest ×5, DoctrineRateLimitStoreTest ×1), as planned.

**Risk carried:** `exists()`/`isActivated()` are confirmed on this shop's CE; CI's 7.1–7.3 matrix runs
`OxidActiveModuleQueryTest` against those lines.
