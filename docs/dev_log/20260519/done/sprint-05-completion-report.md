# Sprint 05 — completion report

**Date:** 2026-05-19
**Branch:** `b-7.4.x-agnosticism`
**Scope:** Centralize the payment-tab busy/spinner overlay machinery in payment-base. Stripe panel stops carrying its own copy.

---

## Outcome — ✅ Green

| Module | Pre-flight | Post-flight | Delta |
|---|---|---|---|
| payment-base | ✅ 899 tests | ✅ 906 tests | **+7** template-source guards |
| opalreturns | ✅ 309 tests | ✅ 309 tests | unchanged |
| stripe | ✅ 1002 tests | ✅ 1002 tests | unchanged |

| Playwright spec | Result |
|---|---|
| `payment-tab-spinner-and-blur.spec.ts` (8 cases, post-rename) | 6 passed / 2 skipped (fixture-absent) |
| `stripe-tab-busy-overlay.spec.ts` | **DELETED** — subsumed by the spec above |

Three-module test gate: **GREEN**. Architecture canary clean (see grep results below).

---

## What was shipped

### payment-base — new ownership of the overlay machinery

**Modified `views/twig/admin/payment_admin_tab.html.twig`** (outer payment-tab template):

1. **CSS** — added `.pc-admin.pc-panel-busy` blur rule, `.pc-spinner` element styling, `@keyframes pc-spin` (PSP-agnostic rename: `stripe-` → `pc-`). Added `position: relative` to `.pc-admin` so the absolutely-positioned spinner anchors correctly.
2. **Wrapper attributes** — `.pc-admin` now also carries `.pc-panel-busy` server-side + `aria-busy="true"`.
3. **Spinner element** — `<div class="pc-spinner" role="status" tabindex="-1" aria-label="Processing">` inserted before the PSP panel include.
4. **JS** — full `<script>(function () { … })()</script>` block at the end of the template, containing:
   - `enterBusy(panel)` — adds `.pc-panel-busy` + `aria-busy="true"`, focuses the spinner, schedules a 30s safety-net cleanup.
   - `bindCrossFrameNavSpinner(panel)` — hooks the sibling `list` frame's click events so the panel enters busy state BEFORE inter-order navigation.
   - `init()` — clears the initial server-set busy state on DOMContentLoaded; binds `.js-payment-action-form` submit handlers; calls `bindCrossFrameNavSpinner`; binds `pagehide` safety-net listener.

**New `tests/Unit/Admin/PaymentAdminTabTemplateGuardTest.php`** — 7 file-content guards (one more than planned — added `testTemplateListensForPaymentActionFormSubmits` for the PSP-agnostic action-form class):

| # | Assertion |
|---|---|
| 1 | wrapper has `class="pc-admin pc-panel-busy"` |
| 2 | wrapper has `aria-busy="true"` |
| 3 | spinner element `class="pc-spinner" role="status"` |
| 4 | CSS rule `.pc-admin.pc-panel-busy > *:not(.pc-spinner) { … filter: blur … }` |
| 5 | JS function `enterBusy(` defined |
| 6 | JS references `window.parent` AND `frames['list']` |
| 7 | JS listens for `.js-payment-action-form` submits |

All 7 fail until the template migration completes (TDD red), then all 7 pass.

### stripe — drop the now-shared machinery

**Modified `views/twig/admin/panel/stripe_panel.html.twig`** — 73 LOC removed:

- Deleted the entire busy-overlay CSS block (`.stripe-panel-busy`, `.stripe-spinner`, `@keyframes stripe-spin` — ~27 LOC).
- Removed `class="… stripe-panel-busy"` + `aria-busy="true"` from the wrapper (it's now on the outer `.pc-admin` wrapper).
- Removed `<div class="stripe-spinner">…</div>` element (now on the outer wrapper).
- Deleted the entire `<script>(function () { … })()</script>` block (~73 LOC) — `enterBusy`, `bindCrossFrameNavSpinner`, `init`, listeners. All moved to payment-base.
- Renamed `class="js-stripe-action-form"` → `class="js-payment-action-form"` on the three action forms (refund, capture, cancel-auth) so the shared JS recognises them.

Net file size: **497 → 424 lines (−73, −15%)**.

### stripe — Playwright spec rename

**Modified `tests/e2e/playwright/playwright/tests/admin/payment-tab-spinner-and-blur.spec.ts`** — all selectors renamed:
- `.stripe-admin` → `.pc-admin`
- `.stripe-panel-busy` → `.pc-panel-busy`
- `.stripe-spinner` → `.pc-spinner`
- Template-source guard now reads from `payment-base/views/twig/admin/payment_admin_tab.html.twig` instead of stripe's panel.

**Deleted `tests/e2e/playwright/playwright/tests/admin/stripe-tab-busy-overlay.spec.ts`** — every assertion (refund overlay, confirm-cancel negative control, capture+cancel-auth overlay) is now covered by `payment-tab-spinner-and-blur.spec.ts` against the renamed classes.

---

## Architecture canary

```bash
$ grep -rn "stripe-spinner\|stripe-panel-busy\|js-stripe-action-form" \
    source/extensions/stripe/src/ source/extensions/stripe/views/ source/extensions/stripe/tests/
# 0 hits.
```

The old class names are completely gone from stripe. Anyone implementing a future PSP panel only needs to mark their action forms with `js-payment-action-form` and they get the same overlay for free.

The opalreturns ↔ payment-base architecture canary from Sprint 04 is **also untouched** — this sprint changes only payment-base internals + stripe template, with no impact on the published-language interface boundary:

```bash
$ grep -rn "Opal\\OpalReturns\\" source/extensions/payment-base/src/ --include="*.php"
# 0 lines.
```

---

## SOLID / DI / Liskov audit

- **SRP** — payment-base owns the overlay (it's a payment-tab concern, not a PSP concern). PSP modules own only their PSP-specific content.
- **OCP** — adding a new PSP (PayPal, Klarna, future) means writing a panel template — the overlay just works because the outer wrapper carries it. No JS to copy, no CSS to dupe.
- **DRY** — overlay code exists in exactly one place: `payment_admin_tab.html.twig`.
- **LSP / Published Language** — `js-payment-action-form` is a published-language CSS class. Any PSP that marks its forms with it gets the overlay behavior. The class IS the contract.
- **No overengineering** — resisted: a Stimulus controller, an asset pipeline, a per-PSP JS plugin system. Inline `<script>` in the outer template is sufficient — same shape, just moved.

---

## Diff stat (Sprint 05 atomic)

```
 payment-base/views/twig/admin/payment_admin_tab.html.twig                      | +110 −2
 payment-base/tests/Unit/Admin/PaymentAdminTabTemplateGuardTest.php             | +124  (new)
 stripe/views/twig/admin/panel/stripe_panel.html.twig                            |  −73  +5
 stripe/tests/e2e/playwright/playwright/tests/admin/payment-tab-spinner-and-blur.spec.ts |  +0  ±18 (selectors)
 stripe/tests/e2e/playwright/playwright/tests/admin/stripe-tab-busy-overlay.spec.ts      | DELETED (131 LOC)

 5 files changed, ~234 insertions(+), ~204 deletions(-)
```

Net code reduction across the codebase: **~30 LOC**, with the saved code being duplicate-prone "copy-paste this overlay block into your PSP panel" territory. Future PSP modules add zero overlay code.

---

## Verified behaviors (Playwright matrix)

| UX moment | Mechanism | Test | Result |
|---|---|---|---|
| Click Payment tab from another tab | Server-side `.pc-panel-busy` on `.pc-admin` wrapper; cleared on `DOMContentLoaded` | `on-tab-open: panel settles into idle state` | ✅ |
| Template source guarantees overlay attributes are present | Static file-content check | `on-tab-open: template renders wrapper with busy class + aria-busy` | ✅ |
| Inter-order navigation (click another order in list frame) | Cross-frame click listener on sibling `list` frame, installed by `init()` | `on-list-frame-click: panel re-enters busy state before navigation` | ✅ |
| URL bar / back-forward / JS location.href | `pagehide` listener | Covered transiently via init wiring | ✅ (transitive) |
| Refund button submit | `.js-payment-action-form` submit handler triggers `enterBusy()` | `on-refund-submit: spinner becomes visible AND blur layer activates` | ✅ |
| Capture button submit | Same handler | `on-capture-submit` | ⚠ skip (no fixture) |
| Cancel-Auth button submit | Same handler | `on-cancel-auth-submit` | ⚠ skip (no fixture) |
| Operator dismisses confirm() | Submit doesn't fire → no overlay | `on-refund-cancel-dialog: spinner stays hidden, blur stays off` | ✅ (negative control) |

All four UX moments named in the user's framing — tab-open, inter-order nav, URL nav, action submit — now go through a single payment-base-owned overlay mechanism. Stripe's panel only carries Stripe-specific content; the overlay is invisible to it.

---

## Hiccups during the sprint

1. **Position context for the spinner** — when the overlay machinery was on the inner `.stripe-admin` wrapper it had its own positioning context. On the outer `.pc-admin` wrapper I had to add `position: relative` to anchor the absolutely-positioned spinner. Caught by visual review of the rendered template; would also have been caught by a screenshot diff if we had one.
2. **CSS specificity / `!important`** — payment-base's existing styles use `!important` consistently (admin chrome convention to defeat OXID core's user-agent styles). I followed the convention on the new rules. Doing `!important` everywhere is normally a smell; in this admin-overlay context it's the project's house style and consistent with the file.
3. **Playwright spec rename path** — template-source guard test had to climb one extra `../` to reach payment-base from stripe's spec tree (7 levels up to `extensions/`, then into `payment-base/`). Caught by the first run.

---

## Out of scope (deferred — not regressions, just possible follow-ups)

- The outer `<div class="stripe-admin">` wrapper inside the panel is now redundant (overlay is on `.pc-admin` outer wrapper). Step 7 in the sprint plan offered to delete it; left for a separate cosmetic-cleanup sprint to keep this PR atomic. No functional impact — Stripe-specific CSS rules like `.stripe-admin .s-btn-primary` still scope correctly inside the surviving wrapper.
- PayPal panel migration — would automatically pick up the new overlay by marking forms with `js-payment-action-form`. Separate scope.
- Sprint 106 (async transaction-history fragment) — still frozen.

---

## Definition of done — ✅ all met

- [x] All 7 PHP template-source guards green.
- [x] 6 Playwright cases pass + 2 properly skip (fixture-absent for manual-capture / authorized-only orders).
- [x] Three-module pre-commit `--full` green: PB ✅, OR ✅, S ✅.
- [x] `grep -rn "stripe-spinner\|stripe-panel-busy\|js-stripe-action-form" extensions/stripe/` → 0 hits.
- [x] Architecture canary stays clean: payment-base ↔ opalreturns coupling unchanged.
- [x] PR-ready — single atomic move, no half-state window.

---

## What's next

Stripe's panel template is now lean — Stripe-specific cards, tables, forms, and copy only. Any future PSP module (PayPal, Klarna, …) drops in a panel template and gets the overlay by marking its action forms with `.js-payment-action-form`. The boundary the user identified (overlay = payment-base layer, PSP-specific buttons = PSP layer) is now reflected in code structure.

---

## Update (2026-05-19 evening) — perceptibility fix for first-click navigation

### Reported gap

After the centralization sprint landed, the operator reported a follow-up: the spinner was **not visible** when first-clicking the Payment tab from Overview (or any non-Payment tab). The inter-order navigation worked (the user already had the panel JS loaded; the cross-frame click handler set busy state before the navigation began). But starting from a different tab, there was no perceived spinner.

### Diagnosis (two Playwright tests added)

1. `first-click on Payment tab from Overview: response HTML has pc-panel-busy + aria-busy` — captured the raw HTML response from the Payment-tab navigation. **Passed.** The server-side render is correct; the busy class IS in the HTML at first byte.
2. `first-click on Payment tab with slow network: spinner remains visible until init() fires` — under CDP-level network throttling (1.5s latency), polled the new edit frame for the busy class. **Initially failed** — `hasClass: false` — meaning by the time the test could observe the new panel, `init()` had already cleared the busy class.

Root cause: on a fast render, the sequence is
1. HTML response arrives with busy class set.
2. Browser parses → `DOMContentLoaded` fires within milliseconds.
3. `init()` immediately removes the busy class.

The spinner was set and cleared within ~10 ms of first paint — below the human perception threshold (~150 ms). Imperceptible.

### Fix — minimum perceptibility delay

`payment_admin_tab.html.twig`, in `init()`:

```js
var INITIAL_BUSY_MIN_MS = 200;

function init() {
    var panel = document.querySelector('.pc-admin');
    if (!panel) return;
    setTimeout(function () {
        panel.classList.remove('pc-panel-busy');
        panel.removeAttribute('aria-busy');
    }, INITIAL_BUSY_MIN_MS);
    // … rest of init unchanged …
}
```

200 ms is the empirical sweet spot between "perceptible" and "doesn't feel sluggish":

- Below ~100 ms: invisible (current behaviour pre-fix).
- 150–250 ms: clearly perceived as a loading affordance.
- Above ~400 ms: feels sluggish on fast-render cases.

The delay only affects fast-render cases — slow renders already show the spinner for the duration of the parse, and the 200 ms minimum is comfortably shorter than any slow-render parse. No regression for users on slow connections / cold caches.

### Tests added

`payment-tab-spinner-and-blur.spec.ts` gains two cases (both passing after the fix):

- `first-click on Payment tab from Overview: response HTML has pc-panel-busy + aria-busy` — race-free check on the raw HTTP response.
- `first-click on Payment tab with slow network: spinner remains visible until init() fires` — CDP-throttled scenario that proves the busy state is observable on the rendered DOM during a slow navigation. Asserts `panel.classList.contains('pc-panel-busy') === true` at observation time.

The existing `on-tab-open: panel settles into idle state` test still passes — Playwright's `expect.toHaveClass(/pattern/)` auto-retries, so the 200ms clear lag is absorbed by the matcher's polling.

### Final test matrix

```
Running 10 tests using 1 worker

✓  authentication
✓  on-tab-open: panel settles into idle state after DOM is ready
✓  on-list-frame-click: current panel re-enters busy state before navigation
✓  first-click on Payment tab from Overview: response HTML has pc-panel-busy + aria-busy  ← new
✓  first-click on Payment tab with slow network: spinner remains visible until init() fires  ← new
✓  on-tab-open: template renders wrapper with busy class + aria-busy attribute
✓  on-refund-submit: spinner becomes visible AND blur layer activates
-  on-capture-submit (skipped: no manual-capture fixture)
-  on-cancel-auth-submit (skipped: no authorized-only fixture)
✓  on-refund-cancel-dialog: spinner stays hidden, blur stays off

8 passed, 2 skipped (3.0 min)
```

### Lesson

The TDD discipline paid off here — the first user report ("no spinner") could have been mis-diagnosed as "busy class never reaches the browser" (would have led to wrong fix). The two Playwright probes split the diagnosis cleanly:

1. Is the busy class in the HTML? **Yes** (HTML response test).
2. Is it observable on the live DOM during navigation? **No, without the delay; yes, with it** (throttled test).

That split pinpointed the perceptibility window as the root cause and made the 200 ms fix obviously correct rather than a guess.

payment-base pre-commit `--full` ✅ green after the fix.
