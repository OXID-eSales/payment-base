# Sprint 05 — Centralize the payment-tab busy/spinner overlay in payment-base

**Branch:** `b-7.4.x-agnosticism` (shared across payment-base, opalreturns, stripe)
**Module:** primarily `payment-base`; consumer-side cleanup in `stripe`
**Closes / supersedes:** reports
[03-payment-tab-spinner-blur-verification.md](../reports/03-payment-tab-spinner-blur-verification.md)
and
[04-payment-tab-on-open-spinner-added.md](../reports/04-payment-tab-on-open-spinner-added.md)
— the overlay machinery currently lives in `stripe`'s panel template (markup
+ CSS + JS) but its purpose is generic payment-tab UX, not Stripe-specific.
**Estimated size:** ~250 LOC moved/renamed, ~150 LOC new tests, net code reduction in stripe.

---

## 1. Why

After reports 03 and 04, the busy-overlay mechanism covers four UX moments:

1. **Tab-open render** — server-side `.stripe-panel-busy` on the wrapper.
2. **Action-button submit** — JS handler on `.js-stripe-action-form` adds busy class.
3. **Inter-order navigation** — cross-frame click listener on the sibling `list` frame.
4. **URL-bar / back-forward navigation** — `pagehide` safety net.

Of these, **only #2 (action-button submit) is genuinely PSP-specific** — the buttons themselves are Stripe's. The other three moments are **payment-tab-generic**: they would apply identically to any PSP that drops a panel into the shared "Payment" admin tab.

Today all four behaviors are implemented in `extensions/stripe/views/twig/admin/panel/stripe_panel.html.twig`. If PayPal (or any future PSP module) adds its own panel, it will either:
- Re-implement the same overlay (copy-paste drift), or
- Get no overlay at all (degraded UX).

**Goal of this sprint:** the overlay machinery (markup, CSS, JS) moves into the **outer** payment-tab template owned by payment-base. PSP panels (stripe, future paypal) only need to mark their action forms with a PSP-agnostic class so the shared JS recognises them. Single source of truth, zero copy-paste.

---

## 2. Architecture target

### Current

```
payment-base/views/twig/admin/payment_admin_tab.html.twig
   ↓ {% include oView.getPanelTemplatePath() %}
stripe/views/twig/admin/panel/stripe_panel.html.twig
   ├─ <div class="stripe-admin stripe-panel-busy">       ← overlay wrapper
   ├─ <div class="stripe-spinner">                       ← overlay element
   ├─ <style> .stripe-panel-busy { … } .stripe-spinner { … } </style>
   ├─ <form class="js-stripe-action-form refundSubmit">  ← PSP-specific form
   └─ <script> enterBusy(), DOMContentLoaded, cross-frame, pagehide </script>
```

### Target

```
payment-base/views/twig/admin/payment_admin_tab.html.twig
   ├─ <div class="pc-admin pc-panel-busy" aria-busy="true">  ← overlay wrapper (was in stripe)
   ├─ <div class="pc-spinner">                               ← overlay element (was in stripe)
   ├─ <style>  .pc-panel-busy { blur } .pc-spinner { … } </style>  ← (was in stripe)
   ├─ {% include oView.getPanelTemplatePath() %}                    ← PSP-specific content
   └─ <script> enterBusy(), DOMContentLoaded, cross-frame, pagehide </script>  ← (was in stripe)

stripe/views/twig/admin/panel/stripe_panel.html.twig
   ├─ Stripe-specific cards, tables, copy
   └─ <form class="js-payment-action-form refundSubmit">  ← only the class renamed
```

### Renaming map (PSP-agnostic naming)

| Current (Stripe-owned) | Target (payment-base-owned) | Rationale |
|---|---|---|
| `.stripe-admin` | `.pc-admin` | Already used by payment-base's outer wrapper — collide-and-merge. |
| `.stripe-panel-busy` | `.pc-panel-busy` | PSP-agnostic, sits on the same wrapper. |
| `.stripe-spinner` | `.pc-spinner` | PSP-agnostic. |
| `.js-stripe-action-form` | `.js-payment-action-form` | PSP-agnostic; PayPal/etc. mark the same way. |

Note: payment-base's outer template ALREADY uses `.pc-admin` for its wrapper. The current `stripe-admin` wrapper sits **inside** `pc-admin` — that nesting goes away (the inner wrapper merges into the outer one, since both are just "the panel container"). One wrapper class for the whole panel.

---

## 3. TDD plan — failing tests first

### 3a. PHP-side tests (payment-base)

Create `payment-base/tests/Unit/Admin/PaymentAdminTabTemplateGuardTest.php` — a focused, file-content guard so the template's load-bearing attributes can never silently disappear. Same pattern as the existing `SymfonyServiceIdClashTest`'s template guard. **Six assertions**, all on the raw Twig source:

1. `testTemplateRendersWrapperWithBusyClassAtFirstPaint()` — `class="pc-admin pc-panel-busy"` present.
2. `testTemplateRendersAriaBusyAttribute()` — `aria-busy="true"` present on wrapper.
3. `testTemplateContainsSpinnerElement()` — `class="pc-spinner"` with `role="status"`.
4. `testTemplateContainsBusyCss()` — `.pc-panel-busy` CSS rule with `filter: blur`.
5. `testTemplateContainsEnterBusyFunction()` — JS function name `enterBusy(` present.
6. `testTemplateContainsCrossFrameNavHook()` — `window.parent.frames['list']` reference present.

These tests document the load-bearing parts of the template so a future contributor who refactors / minifies can't accidentally drop one.

### 3b. JavaScript behavior tests (payment-base)

Two approaches, pick **only one** depending on existing tooling:

- **Option A — JSDom unit tests via PHPUnit dataset**: payment-base doesn't currently ship a JS test runner; standing up one for ~30 lines of inline JS is overengineering.
- **Option B — Playwright integration tests** that exercise the actual rendered template in a real browser. payment-base already runs Playwright via the stripe E2E harness (cross-module test convention).

**Pick Option B.** The JS behaviors map cleanly onto Playwright assertions, and the same harness already covers Sprint 107 + reports 03/04 cases.

The Playwright tests (see §3c) ARE the JS behavior coverage. Skip Option A entirely.

### 3c. Playwright tests (move + rename + extend)

Move/rename `extensions/stripe/tests/e2e/playwright/playwright/tests/admin/payment-tab-spinner-and-blur.spec.ts` so its selectors use the new class names. The spec STAYS in stripe's E2E harness (only PSP integration tests get the full admin auth setup), but assertions target `pc-` prefixes. Test list (8 cases — adds the renamed `stripe-tab-busy-overlay` cases too):

1. **on-tab-open** — `pc-admin.pc-panel-busy` set server-side; `aria-busy="true"`; `pc-spinner` visible during parse; cleared after `domcontentloaded`.
2. **on-tab-open: template guard** — `payment_admin_tab.html.twig` source contains the busy class + spinner element (the file path moves from stripe to payment-base; the test reads the new path).
3. **on-action-submit (refund)** — clicking `.refundSubmit` enters busy; spinner visible; aria-busy=true. Tests that the JS recognises `js-payment-action-form` (renamed from `js-stripe-action-form`).
4. **on-action-submit (capture)** — same overlay; skips when fixture absent.
5. **on-action-submit (cancel-auth)** — same overlay; skips when fixture absent.
6. **on-confirm-cancel** — operator dismisses confirm() → no overlay.
7. **on-list-frame-click** — synthesised click in sibling `list` frame triggers `enterBusy` on the panel before navigation.
8. **on-pagehide** — re-entering busy state when the page is about to be replaced (URL-bar / back-button safety net).

All eight tests written first, will fail until the migration in §4 completes.

---

## 4. Production migration (step-by-step)

### Step 1 — payment-base: write the failing PHP template guards (§3a).
Run; all 6 must fail because the template doesn't yet contain the expected markup.

### Step 2 — payment-base: move overlay machinery into `payment_admin_tab.html.twig`.

- Move the CSS for `.stripe-panel-busy`, `.stripe-spinner`, and the keyframes from `stripe_panel.html.twig` → `payment_admin_tab.html.twig`. Rename selectors to `.pc-panel-busy` / `.pc-spinner` on the way.
- Add `.pc-panel-busy` + `aria-busy="true"` to the existing `.pc-admin` wrapper attribute list (line 102 of the outer template).
- Add the `<div class="pc-spinner" role="status">` element inside the wrapper, just before the `{% include … %}`.
- Move the entire `<script>(function () { … })()</script>` block from `stripe_panel.html.twig` → end of `payment_admin_tab.html.twig`. Rename:
  - `.stripe-admin` → `.pc-admin` (one selector)
  - `.stripe-panel-busy` → `.pc-panel-busy`
  - `.stripe-spinner` → `.pc-spinner`
  - `.js-stripe-action-form` → `.js-payment-action-form`

Run guards (§3a); all 6 must now pass.

### Step 3 — stripe: drop the moved code from `stripe_panel.html.twig`.

- Delete the `<style>` rules for `.stripe-panel-busy` and `.stripe-spinner`.
- Delete the `<div class="stripe-spinner">…</div>` element.
- Remove `.stripe-panel-busy` and `aria-busy="true"` from the outer `<div class="stripe-admin …">` wrapper.
- Delete the entire `<script>(function () { … })()</script>` block at the bottom of the template.
- Rename `class="js-stripe-action-form"` → `class="js-payment-action-form"` on the three action forms (refund, capture, cancel-auth).
- Leave Stripe-specific styles (`.stripe-admin .s-btn-primary`, etc.) — those are unrelated to the overlay.
- **DO NOT** delete the outer `<div class="stripe-admin" id="stripeContent" data-testid="stripe-panel-card">` wrapper yet — Playwright selectors still reference `stripe-panel-card`. Defer to step 7.

Run stripe pre-commit. PHPUnit, PHPStan, PHPCS all green. Playwright will be red until step 4.

### Step 4 — Playwright spec rewrite (§3c).

Update `payment-tab-spinner-and-blur.spec.ts` so every selector uses the new class names:
- `.stripe-admin` → `.pc-admin`
- `.stripe-panel-busy` → `.pc-panel-busy`
- `.stripe-spinner` → `.pc-spinner`
- Template-source path → `payment-base/views/twig/admin/payment_admin_tab.html.twig`

Add the new tests `on-pagehide` and `on-list-frame-click` (the latter already exists from the report-04 update; rename its selectors).

DELETE the now-redundant `stripe-tab-busy-overlay.spec.ts` — every assertion it makes is now in `payment-tab-spinner-and-blur.spec.ts` (Sprint 107 was about action-form submit only; that's test #3 + #4 + #5 + #6).

Run Playwright: all eight cases green.

### Step 5 — Page-object update.

`extensions/stripe/tests/e2e/playwright/playwright/pages/admin/AdminStripeOrderPage.ts` — if any selectors hardcode `stripe-spinner` or `stripe-admin`, swap to `pc-spinner` / `pc-admin`. (Likely only `getStripePaymentDetails()` and similar reference these; verify with grep before editing.)

### Step 6 — Three-module gate.

```bash
docker compose exec -w /var/www/extensions/payment-base -T php ./bin/pre-commit-check.sh --full
docker compose exec -w /var/www/extensions/opalreturns  -T php ./bin/pre-commit-check.sh --full
docker compose exec -w /var/www/extensions/stripe       -T php ./bin/pre-commit-check.sh --full
```
All three must be `COMMITABLE`.

```bash
cd extensions/stripe/tests/e2e/playwright/playwright
HEADLESS=true npx playwright test --project=admin-tests tests/admin/payment-tab-spinner-and-blur.spec.ts --reporter=list
```
8 passed (or 6 passed + 2 skipped if manual-capture / authorized-only fixtures absent).

### Step 7 — Final cleanup (optional, scope-permitting).

The outer Stripe wrapper `<div class="stripe-admin" id="stripeContent" data-testid="stripe-panel-card">` is now redundant — `.pc-admin` is the wrapper. Drop it; promote `data-testid` and `id` to the `.pc-admin` wrapper itself (the parent template).

Update any Playwright selectors that target `stripe-admin` or `stripe-panel-card` accordingly.

If this triggers PHPCS / Playwright regressions outside the spinner scope (unlikely but possible because `stripe-admin` is also a CSS prefix for some Stripe button styles), revert the rename of the wrapper class itself but keep the rename of `stripe-panel-busy` / `stripe-spinner`. The overlay-classes rename is the load-bearing part.

---

## 5. Acceptance criteria

- [ ] `payment-base/views/twig/admin/payment_admin_tab.html.twig` contains the spinner element, busy-class CSS, and JS that:
  - Sets `pc-panel-busy` server-side and clears it on `DOMContentLoaded`.
  - Listens for submits on `.js-payment-action-form` forms.
  - Listens for clicks in the sibling `list` frame.
  - Re-enters busy on `pagehide`.
- [ ] `stripe/views/twig/admin/panel/stripe_panel.html.twig` contains **no** `<style>` rules for `pc-panel-busy` / `pc-spinner`, **no** spinner DOM element, **no** `<script>` overlay block. Only Stripe-specific markup + action-form class renamed to `js-payment-action-form`.
- [ ] The 6 PHP template-source guards in `PaymentAdminTabTemplateGuardTest` pass.
- [ ] All 8 Playwright cases pass (or 6 pass + 2 skip when fixtures absent).
- [ ] Three-module pre-commit `--full` green: payment-base, opalreturns, stripe.
- [ ] Grep guards (production code only):
  ```bash
  grep -rn "stripe-spinner\|stripe-panel-busy\|js-stripe-action-form" source/extensions/stripe/src/ source/extensions/stripe/views/ --include="*.php" --include="*.twig" --include="*.js"
  # Expected: 0 lines (production-side; tests/ may keep transitional aliases if needed)
  ```

---

## 6. SOLID / DI / Liskov notes

- **SRP**: payment-base owns the payment-tab overlay (it's a payment-tab concern, not a PSP concern). PSP modules own their PSP-specific content.
- **OCP**: adding a new PSP (PayPal, Klarna, future) means writing a panel template — the overlay just works. No JS to copy, no CSS to dupe.
- **DRY**: overlay code exists in exactly one place.
- **LSP**: the `js-payment-action-form` class is a published-language convention — any PSP panel that marks its forms with this class gets the overlay behaviour. The class IS the contract.
- **No overengineering**: resist adding a Stimulus controller, an asset pipeline split, or a per-PSP JS plugin system. Inline `<script>` in the outer template is sufficient — same shape as it already is, just moved.

---

## 7. Three-module test gate (mandatory)

### Pre-flight (before writing any code)

```bash
docker compose exec -w /var/www/extensions/payment-base -T php ./bin/pre-commit-check.sh --full
docker compose exec -w /var/www/extensions/opalreturns  -T php ./bin/pre-commit-check.sh --full
docker compose exec -w /var/www/extensions/stripe       -T php ./bin/pre-commit-check.sh --full
```

All three green. Plus the Playwright admin suite:

```bash
cd extensions/stripe/tests/e2e/playwright/playwright
HEADLESS=true npx playwright test --project=admin-tests tests/admin/payment-tab-spinner-and-blur.spec.ts tests/admin/stripe-tab-busy-overlay.spec.ts --reporter=list
```

Record baseline numbers + green/red per test.

### Post-flight (before opening the PR)

Re-run all three pre-commits + the Playwright admin suite (after the rename, `stripe-tab-busy-overlay.spec.ts` is deleted — only `payment-tab-spinner-and-blur.spec.ts` runs).

- Stripe test count drops by the Sprint-107 spec deletion (~3 tests removed; new spec covers them via the renamed action-button tests).
- payment-base test count rises by the 6 new template-source guards.
- opalreturns unchanged.

Attach pre + post numbers to the PR description.

---

## 8. Risks

| Risk | Mitigation |
|---|---|
| PSP CSS leaks — `.pc-admin .s-btn-primary` selectors in stripe panel depend on the old `.stripe-admin` parent class | After the rename, scope Stripe-specific button styles to `.pc-admin[data-provider="stripe"]` — the outer template already exposes `data-provider="{{ oView.getProviderKey() }}"`. The hook is already there. |
| Twig cache holds the old template | Always `rm -rf source/tmp/*` + `oe:cache:clear` between iterations. |
| The cross-frame click listener references frame name `'list'` — may differ across OXID versions | Existing implementation already references `'list'` (working). No change to behavior. |
| Stripe-specific Playwright Page Objects hardcode `.stripe-spinner` | Grep before renaming; update the Page Object in lockstep. |
| Outer `<div class="stripe-admin">` wrapper still referenced by other Stripe tests (selectors like `getStripePaymentDetails`) | Defer the wrapper rename to step 7 (optional). The overlay-class rename is the load-bearing change; the wrapper rename is cosmetic. |
| Breaking the action-button overlay during the move | TDD discipline: the Playwright `on-action-submit (refund)` test is rewritten FIRST (red), then the migration makes it green. No window during which the overlay is broken on real orders. |

---

## 9. Out of scope

- A JS asset pipeline / Stimulus controller. Inline scripts stay inline.
- A PayPal panel migration (would re-use the same overlay automatically, but PayPal panel work is separate scope).
- The outer `data-provider="stripe"` attribute — keep as is; it's the right hook for PSP-specific styling without coupling.
- Sprint 106 (async transaction-history fragment) — still frozen. This sprint doesn't touch that scope.

---

## 10. Definition of done

- All 6 PHP template-source guards green.
- All 8 Playwright cases green or properly skipped.
- Three-module pre-commit `--full` green.
- `grep -rn "stripe-spinner\|stripe-panel-busy\|js-stripe-action-form" source/extensions/stripe/src/ source/extensions/stripe/views/` → 0 hits.
- `grep -rn "Opal\\OpalReturns\\" source/extensions/payment-base/src/` → 0 hits (architecture canary stays clean — this sprint doesn't touch opalreturns).
- PR opened; this file moved to `done/` with a completion note.
- A short retro under `done/sprint-05-completion-report.md`:
  - LOC moved from stripe → payment-base
  - LOC deleted from stripe (net negative)
  - Test counts diff
  - Whether step 7 (wrapper rename) shipped or was deferred
  - Any surprises (Twig cross-module include quirks, CSS specificity battles, etc.)

---

## Appendix A — files touched

**payment-base (new):**
- `tests/Unit/Admin/PaymentAdminTabTemplateGuardTest.php`

**payment-base (modified):**
- `views/twig/admin/payment_admin_tab.html.twig` — gains the overlay CSS, spinner element, busy-class wrapper, and the `<script>` block from stripe.

**stripe (modified):**
- `views/twig/admin/panel/stripe_panel.html.twig` — loses overlay CSS, spinner element, busy class on wrapper, `<script>` block. Three action forms rename `js-stripe-action-form` → `js-payment-action-form`.

**stripe (Playwright):**
- `tests/e2e/playwright/playwright/tests/admin/payment-tab-spinner-and-blur.spec.ts` — selectors renamed; template-guard test points at payment-base path; 8 cases.
- `tests/e2e/playwright/playwright/tests/admin/stripe-tab-busy-overlay.spec.ts` — DELETED (subsumed by the spec above).
- `tests/e2e/playwright/playwright/pages/admin/AdminStripeOrderPage.ts` — any spinner-related selectors renamed.

**opalreturns:** **no changes**. The architecture canary stays clean.

---

## Appendix B — Why one sprint, not two

Splitting "add to payment-base" from "remove from stripe" into two sprints would leave the codebase in a half-state where both modules render the same CSS class and JS handlers — risk of double-firing the busy state on action submit. Keep them in one atomic PR.

The TDD discipline (red → green) prevents a "no overlay" window: the failing Playwright `on-action-submit (refund)` test is rewritten BEFORE the stripe-side code is removed, then both moves land in the same diff that flips it green.
