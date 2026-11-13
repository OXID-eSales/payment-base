# Sprint 29 (VRL) S0 — local reproduction and R1

- Probed the shop container's own services (`ModuleActivationBridge`, module configuration, rules file, `ValidationBase` with the container's loader).
- Problems 5 and 6 confirmed: invented/unconfigured ID → `ModuleConfigurationNotFoundException` (500); `oe_payment_base` → no rules file (500).
- **R1 answered:** Stripe and Mollie resolve their rules and validate without exception locally, so no extra story.
- HTTP repro via curl was blocked by the tunnel session (401 at `ActiveSessionGuard`); moved to S6. The S1 integration test measures rows on the real guard chain.
- Details: `../reports/01-mythos-o08-…md`, section "Local reproduction".
- No code changed.
