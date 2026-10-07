# Sprint 15 / S2 — Checkout context instead of `$_SESSION` flags (DONE 2026-10-06)

**Sprint:** [sprint-15-GRAPH-QL-headless-checkout.md](../sprints/sprint-15-GRAPH-QL-headless-checkout.md) · **Branch:** `b-7.4.x-GRAPH-QL`

## What changed

| Piece | Job |
|---|---|
| `Checkout\Context\CheckoutContextInterface` | `getScopeId()`, `get()`, `set()`, `remove()` — the per-attempt flags without saying where they live |
| `Checkout\Context\SessionCheckoutContext` | the shop session via `SessionAdapterInterface`, same keys as before; `remove()` writes null (the adapter has no delete; every reader treats null as unset) |
| `Checkout\Context\HeadlessCheckoutScopeInterface` + `HeadlessCheckoutScope` | which headless checkout this request is about: `enter(scopeId, userId, basketId)` / `leave()` / `isActive()`. Mutable request-scoped service; a Twig request never enters one |
| `Checkout\Context\PersistedCheckoutContext` | the entered scope's flags, loaded once per scope, saved as a unit through the store; refuses (LogicException) without a scope |
| `Checkout\Context\CheckoutContextStoreInterface` + `DoctrineCheckoutContextStore` | `oe_payments_sessions` (created 2025-10-31, unused until now): `OXID = md5('headless:'.scope)`, `OXSESSIONID = scope`, `OXPROVIDER = 'headless'`, `OXUSERID`/`OXBASKETID`, `OXDATA` JSON, `OXEXPIRES = now + 1 day`; expired rows read as empty; save is an upsert |
| `Checkout\Context\ScopedCheckoutContext` | **bound to the interface**: persisted when the scope is active, session otherwise |
| `OpenCheckoutAttemptRegistry`, `PreviousCheckoutAttemptCleaner`, `PaymentStepSkipGuard` | consume `CheckoutContextInterface`; `PaymentStepSkipGuard` lost its `Registry` seam (optional ctor arg, session default) |
| `services.yaml` | payment-base binds `SessionAdapterInterface` → its `OxidSessionAdapter` (providers' own bindings still win until P-* remove them); scope/contexts/store wired; the three consumers get the interface |

## Red → green

| Test | Covers |
|---|---|
| `Unit\Checkout\Context\HeadlessCheckoutScopeTest` (5) | inactive until entered, owner carried, re-enter replaces, leave, empty id refused |
| `Unit\Checkout\Context\SessionCheckoutContextTest` (5) | scope = session id, same keys, default, remove ⇒ unset |
| `Unit\Checkout\Context\PersistedCheckoutContextTest` (7) | refuses without scope, stores under scope with owner, reads per scope, remove persists, switching scopes reloads |
| `Unit\Checkout\Context\ScopedCheckoutContextTest` (4) | session without scope (never writes the store), persisted with scope (never touches the session), leaving goes back |
| `Unit\Checkout\OpenCheckoutAttemptRegistryTest` (+1 data-provider × 2) | remember → peek → take → forgotten, identical on both contexts |
| `Unit\Checkout\PaymentStepSkipGuardTest` (adapted) | same 8 behaviours over a context double incl. the "shop cannot answer" cases |
| `Unit\Checkout\PreviousCheckoutAttemptCleanerTest`, `…RetryCleanupTest`, `InFlightCheckoutAttemptResolverTest` (adapted) | construction over `SessionCheckoutContext` |
| `Integration\Checkout\Context\DoctrineCheckoutContextStoreTest` (4) | unknown ⇒ empty, round-trip + owner + expiry > 23h, second save updates one row, expired ⇒ empty |

## Gates

- Unit **1433** green (6 pre-existing skips) · Integration **139** green (1 pre-existing skip)
- phpcs clean · PHPStan level max **No errors** (scope behind an interface for the project's Liskov rule) · phpmd clean

## Notes for S3+

- Nothing enters a `HeadlessCheckoutScope` yet; S6 (GraphQL glue) and S7 (ACP) are the entry points. Until then every request is a session request and behaves as before.
- `SessionCheckoutNoticeRelocator` (the `Errors` stash for the Twig thank-you page) stays on the session: it is a Twig rendering concern with no headless counterpart.
- Expired `oe_payments_sessions` rows are not purged yet; the existing `oe:payments:not_finished:cleanup` command is the natural home (follow-up, not blocking).
