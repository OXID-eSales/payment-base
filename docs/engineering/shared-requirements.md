# Shared engineering requirements

_Single source of truth for every sprint across `payment-component`,
`stripe`, `paypal`, `opalreturns`, and `one-page-checkout`.
Each dev-log `status.md` references this file instead of re-stating
the rules. Reviewers block any merge that violates them without an
explicit, named "Deliberately NOT in scope" carve-out in the sprint
spec._

**Source of truth path:**
`source/extensions/payment-component/docs/engineering/shared-requirements.md`.

## TDD-first

Every behavioural change ships with a failing test before the
implementation. Red → green → refactor.

- Unit tests own logic. Mock interfaces, not final classes — use the
  memory's "extract a seam" pattern when you hit a `final` wall.
- Integration tests own the wiring — Doctrine writes, event chains,
  DI construction.
- Playwright owns the browser surface. Minimum one regression spec
  per user-facing feature that crosses a frame boundary.
- Coverage must not shrink between sprints.

## DevOps-first

- `./bin/pre-commit-check.sh` green in every affected module before
  the PR is considered landable. No exceptions, no `--no-verify`.
- CI runs the same script. Local + CI state agree.
- Module activation smoke (`deactivate → activate → cache-clear`)
  runs in pre-commit when a module publishes `metadata.php`.
- Never raise PHPMD / PHPStan baselines to hide new violations —
  fix the code or justify the baseline in the commit message.

## SOLID

- **S** — one class, one reason to change. Thin classes over god
  classes. Stripe's `OrderRefund` hit ECC 62 → we pushed complexity
  into `OrderRefundViewDataProvider` + `OrderActionDispatcher` +
  Sprint-I's panel provider.
- **O** — registries + tagged iterators over `switch ($provider)`.
  `oe.payment.admin_panel`, `opalreturns.resolution_handler`,
  `oe.payment.event_translator` are the canonical patterns.
- **L** — handlers honour their declared input type. Never
  downcast. Events pass context, not provider-specific classes.
- **I** — small interfaces. When a class grows 10+ public methods,
  split the interface before the first consumer depends on it.
- **D** — constructor inject interfaces. `ContainerFactory::get` is
  a controller-boundary seam only. See memory's
  `feedback_oxid_controller_duplication.md` for the narrow window
  where admin controllers are forced into lazy-fetch.

## DRY

- Per-PSP code that isn't PSP-specific belongs in
  `payment-component`. Two identical interfaces with different
  namespaces counts as duplication (Sprint I's lesson — see
  `AdminActionDispatcherInterface`).
- Shared CSS / templates live in PC. PSP-specific painting goes
  through a scoping attribute (`data-provider="stripe"`).
- Event + handler translators stay per-PSP; the broker is the only
  fan-out point.

## Clean code

- 15–25 line methods. Extract helpers when the method grows.
- Early returns, no `else` branches.
- Meaningful names. `$data` and `$result` are smells.
- No comments explaining WHAT the code does (the names already do
  that). Comments explain WHY — hidden constraints, workarounds,
  invariants a reader would be surprised by.
- Small `if` bodies before 3-level nesting.

## No overengineering

- Build for the sprint in hand, not a hypothetical third PSP, not a
  plugin loader nobody asked for.
- Two similar blocks are fine. Three similar blocks → extract.
- No feature flags or compat shims for code that hasn't shipped
  yet.
- Delete deprecated paths in the same PR as the replacement.

## Drop deprecated

- No `@deprecated` window on internal code. Code either ships or
  doesn't.
- Renames happen in one commit — callers, docs, tests, lang keys
  all update together.
- Sprint I deleted per-PSP `OrderRefund` tabs + their tests in the
  same PR as the shared tab — that's the expected shape.

## Architecture invariants

- **PayPal and Stripe are peers.** They share only
  `payment-component`. No PayPal import in Stripe, no Stripe import
  in PayPal — enforced by architecture grep guards in each
  module's pre-commit.
- **opalreturns is PSP-agnostic.** No `OxidEsales\Payments\Stripe`
  or `OxidEsales\Payments\PayPal` references in
  `opalreturns/src/`.
- **No direct contract mutations in controllers/services.** Events
  dispatch; handlers mutate. Controllers only orchestrate dispatch.
  Grep guard in `one-page-checkout/bin/pre-commit-check.sh`.
- **Payment-component owns the admin Payment tab.** PSPs contribute
  panel-provider services tagged `oe.payment.admin_panel` (Sprint
  I). No per-PSP admin-order tabs.
- **Shared settings live in payment-component** (Sprint J onward).
  Live/test mode, debug flag, capture mode — all read via
  `PaymentBase\Service\PaymentConfigServiceInterface`.
  PSP-local settings only for genuinely PSP-specific values
  (Stripe API keys, PayPal webhook id, etc.).

## Memory notes a sprint must check against

Before writing code, check:

- `feedback_oxid_controller_duplication.md` — never list a
  controller in both `metadata.php` `controllers` AND
  `services.yaml` `oxid.view_controller` tag.
- `feedback_oxid_admin_tab_viewdata.md` — never override
  `getViewData()` on admin controllers.
- `feedback_module_settings_vs_shopconfvar.md` — admin settings
  form reads via `ModuleSettingServiceInterface`, not via
  `saveShopConfVar`.
- `feedback_oxid_admin_crossmodule_templates.md` — gate twig
  extensions on `oView.getEditObjectId()`, not `attribute(oView,
  'foo') is defined`.
- `feedback_contract_state_machine.md` — `payment_authorized`
  condition fulfils only when state is `pending`; don't bypass.
- `feedback_php_opcache_fpm.md` — after any class-level change,
  `docker compose restart php`.

## The pre-sprint checklist

Every new sprint spec must answer before "green light":

1. What's the failing test that drives the first commit?
2. Which shared invariant above is load-bearing for this sprint?
3. Which memory note applies?
4. What's "deliberately NOT in scope" — anything a reviewer might
   expect but that we're deferring?
5. What gets deleted? (No net code growth without justification.)
6. What's the rollback plan if activation fails on a shop already
   in production?
